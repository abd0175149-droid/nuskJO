<?php

namespace App\Services\WhatsApp;

use App\Enums\AuditAction;
use App\Enums\AuditSeverity;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\WaAdminNumber;
use App\Models\WaBlock;
use App\Models\WaConversation;
use App\Support\Retention;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * الحظر الرسمي (E31): حظر جهة الاتصال وفكّه عند ميتا (Block Users API) بسبب إلزامي وتدقيق صارم بخطورة warn.
 *
 * - الحظر ممكن خلال 24 ساعة من آخر رسالة للعميل وحدها (شرط ميتا، 131047): الزر معطّل خارجها، ولا نداء.
 * - لا يُكتب حظر محلي إن رفضت ميتا. ونص السبب في wa_blocks وحده: التدقيق يحمل رمز السبب وطوله، لا نصاً حراً.
 * - الحظر لا يضيف opt-out: الإيقاف قرار العميل، والحظر قرار الشركة، ويبقيان منفصلين. ولا يحذف رسالة ولا محادثة.
 * - فك الحظر لا يعيد البوت: الموظف يقرر من الصندوق، فقد يعود العميل بخلاف.
 * - المزامنة (BlockSync) قراءة ومقارنة فقط: لا تغيّر أي حظر عند ميتا تلقائياً، وكل إصلاح بزر من مدير.
 * - لا أداة حظر لبوت العملاء أبداً، وأداة المساعد الإداري خلف علم وبمعاينة ثم «تأكيد» (AdminTools).
 */
final class BlockService
{
    public const REASONS = [
        'spam' => 'إزعاج متكرر',
        'abuse' => 'إساءة',
        'fraud' => 'احتيال أو انتحال',
        'other' => 'أخرى',
    ];

    public const REASON_MIN = 10;

    public const REASON_MAX = 300;

    public const UNBLOCK_REASON_MIN = 5;

    /** كلمة التأكيد لمحادثة بلا رقم ظاهر */
    public const CONFIRM_WORD = 'حظر';

    /** نص السبب بعد «حذف بياناتي» */
    public const ERASED_REASON = '[محذوف بطلب العميل]';

    private static ?bool $available = null;

    /** الجدول والعمود موجودان (بين النشر والهجرة 2026_10_14_000031 لا يوجدان، فلا فلاتر ولا مسارات) */
    public static function available(): bool
    {
        return self::$available ??= Schema::hasTable('wa_blocks') && Schema::hasColumn('wa_conversations', 'blocked_at');
    }

    /** ما يكتبه المدير للتأكيد: آخر 4 أرقام، أو «حظر» لمحادثة بلا رقم */
    public static function confirmToken(WaConversation $c): string
    {
        $digits = PhoneNormalizer::digits((string) ($c->phone ?: $c->wa_phone));

        return strlen($digits) >= 4 ? substr($digits, -4) : self::CONFIRM_WORD;
    }

    /** يقبل الأرقام العربية والهندية واللاتينية، والمسافات حولها */
    public static function confirmMatches(WaConversation $c, string $input): bool
    {
        $expected = self::confirmToken($c);
        $typed = (string) TextNormalizer::forSearch(trim($input));

        return $expected === self::CONFIRM_WORD
            ? $typed === TextNormalizer::forSearch(self::CONFIRM_WORD)
            : hash_equals($expected, PhoneNormalizer::digits($typed));
    }

    /**
     * الحظر. رفض مسبق بلا نداء ميتا (رقم إدارة، موظف، محظورة، مدموجة، سبب ناقص)، ثم قفل المحادثة (نقرتان = حظر واحد)،
     * ثم ميتا، ثم الصف والمحادثة والتدقيق في معاملة واحدة. الفاعل يُنسب تلقائياً (E09): المستخدم، أو admin_number للمساعد.
     *
     * @return array{ok: bool, code: ?string, error: ?string, blocked_at?: string, block_id?: int}
     */
    public static function block(WaConversation $c, ?User $u, string $code, string $reason, string $via = 'dashboard', ?WaAdminNumber $a = null): array
    {
        if (!self::available()) {
            return self::refuse('BLOCK_UNAVAILABLE', 'الحظر غير متاح قبل تشغيل الهجرة.');
        }
        if ($refusal = self::refusal($c)) {
            return $refusal;
        }
        if (!array_key_exists($code, self::REASONS)) {
            return self::refuse('BLOCK_REASON', 'اختر سبب الحظر.');
        }
        $reason = self::cleanReason($reason);
        if (mb_strlen($reason) < self::REASON_MIN || mb_strlen($reason) > self::REASON_MAX) {
            return self::refuse('BLOCK_REASON', 'اكتب تفاصيل السبب من '.self::REASON_MIN.' إلى '.self::REASON_MAX.' حرف.');
        }

        $lock = Cache::lock("wa:block:{$c->id}", 30);
        if (!$lock->get()) {
            return self::refuse('BLOCK_BUSY', 'حظر هذه المحادثة جارٍ الآن.');
        }
        try {
            $c->refresh();
            if ($refusal = self::refusal($c)) {
                return $refusal;
            }

            $r = WhatsAppChannel::blockUsers($c);
            if (!$r['ok']) {
                AuditLog::safe(AuditAction::WaBlockFailed, 'wa_conversation', $c->id, null, ['code' => $r['code'], 'meta_code' => $r['meta_code'] ?? null, 'via' => $via]);

                return $r;
            }

            try {
                $block = DB::transaction(function () use ($c, $u, $code, $reason, $via, $a) {
                    $block = WaBlock::create([
                        'conversation_id' => $c->id,
                        'phone' => PhoneNormalizer::digits((string) $c->wa_phone) ?: (PhoneNormalizer::canonical($c->phone) ?: null),
                        'wa_user_id' => $c->wa_user_id ?: null,
                        'reason_code' => $code, 'reason' => $reason,
                        'blocked_by' => $u?->id, 'blocked_via' => $via, 'admin_number_id' => $a?->id,
                        'blocked_at' => now(), 'meta_state' => 'blocked', 'meta_checked_at' => now(),
                    ]);
                    self::markConversation($c);
                    AuditLog::log(AuditAction::WaBlock, 'wa_conversation', $c->id, null,
                        ['reason_code' => $code, 'reason_len' => mb_strlen($reason), 'via' => $via, 'block_id' => $block->id], AuditSeverity::Warn);

                    return $block;
                });
            } catch (\Throwable $e) {
                // ميتا حظرت ولم يُحفظ عندنا: تظهر في المزامنة «محظور عند ميتا بلا سجل»
                WaLog::error('wa.block.local_failed', ['conv_id' => $c->id, 'exception' => $e]);

                return self::refuse('BLOCK_LOCAL_FAILED', 'حُظر الرقم عند ميتا وتعذّر حفظه عندنا. شغّل المزامنة في صفحة المحظورين وسجّل السبب.');
            }
        } finally {
            $lock->release();
        }

        self::notify($c, $u, $a, $code);

        return ['ok' => true, 'code' => null, 'error' => null, 'blocked_at' => $block->blocked_at->toIso8601String(), 'block_id' => $block->id];
    }

    /**
     * فك الحظر بسبب إلزامي. صف not_found (غير محظور فعلياً عند ميتا) يُفك هنا وحده بلا نداء. لا يعود البوت تلقائياً.
     *
     * @return array{ok: bool, code: ?string, error: ?string}
     */
    public static function unblock(WaBlock $b, User $u, string $reason): array
    {
        if (!$b->isActive()) {
            return self::refuse('NOT_BLOCKED', 'هذا الحظر مفكوك أصلاً.');
        }
        $reason = self::cleanReason($reason);
        if (mb_strlen($reason) < self::UNBLOCK_REASON_MIN || mb_strlen($reason) > self::REASON_MAX) {
            return self::refuse('UNBLOCK_REASON', 'اكتب سبب فك الحظر من '.self::UNBLOCK_REASON_MIN.' إلى '.self::REASON_MAX.' حرف.');
        }

        $lock = Cache::lock('wa:block:'.($b->conversation_id ?: 'b'.$b->id), 30);
        if (!$lock->get()) {
            return self::refuse('BLOCK_BUSY', 'عملية على هذا الحظر جارية الآن.');
        }
        try {
            $b->refresh();
            if (!$b->isActive()) {
                return self::refuse('NOT_BLOCKED', 'هذا الحظر مفكوك أصلاً.');
            }
            $localOnly = $b->meta_state === 'not_found';
            $r = $localOnly ? ['ok' => true] : WhatsAppChannel::unblockUsers($b);
            if (!$r['ok']) {
                $b->forceFill(['meta_error_code' => mb_substr((string) $r['code'], 0, 16)])->save();
                AuditLog::safe(AuditAction::WaBlockFailed, 'wa_block', $b->id, null, ['op' => 'unblock', 'code' => $r['code'], 'meta_code' => $r['meta_code'] ?? null]);

                return $r;
            }

            DB::transaction(function () use ($b, $u, $reason, $localOnly) {
                $b->forceFill([
                    'unblocked_at' => now(), 'unblocked_by' => $u->id, 'unblock_reason' => $reason,
                    'meta_state' => 'unblocked', 'meta_error_code' => null, 'meta_checked_at' => $localOnly ? $b->meta_checked_at : now(),
                ])->save();
                if (($conv = $b->conversation) && !WaBlock::active()->where('conversation_id', $conv->id)->exists()) {
                    // bot_enabled يبقى كما هو عمداً
                    $conv->forceFill(['blocked_at' => null])->save();
                }
                AuditLog::log(AuditAction::WaUnblock, 'wa_conversation', $b->conversation_id, null,
                    ['block_id' => $b->id, 'reason_len' => mb_strlen($reason), 'local_only' => $localOnly], AuditSeverity::Warn);
            });
        } finally {
            $lock->release();
        }

        return ['ok' => true, 'code' => null, 'error' => null];
    }

    /**
     * إعادة الحظر عند ميتا لصف وجدته المزامنة غير محظور فعلياً (not_found) أو وصلت منه رسالة (mismatch).
     * يحتاج المحادثة ونافذتها مفتوحة، كأي حظر.
     */
    public static function reblock(WaBlock $b, User $u): array
    {
        if (!$b->isActive() || !in_array($b->meta_state, ['not_found', 'mismatch'], true)) {
            return self::refuse('NOT_REBLOCKABLE', 'هذا الحظر قائم عند ميتا أو مفكوك.');
        }
        $conv = $b->conversation;
        if (!$conv || $conv->erased_at) {
            return self::refuse('NO_CONVERSATION', 'لا محادثة لهذا الحظر: إن راسلنا العميل من جديد يُحظر من الصندوق.');
        }

        $r = WhatsAppChannel::blockUsers($conv);
        if (!$r['ok']) {
            $b->forceFill(['meta_error_code' => mb_substr((string) $r['code'], 0, 16)])->save();
            AuditLog::safe(AuditAction::WaBlockFailed, 'wa_block', $b->id, null, ['op' => 'reblock', 'code' => $r['code'], 'meta_code' => $r['meta_code'] ?? null]);

            return $r;
        }
        DB::transaction(function () use ($b) {
            $b->forceFill(['meta_state' => 'blocked', 'meta_error_code' => null, 'meta_checked_at' => now()])->save();
            AuditLog::log(AuditAction::WaBlock, 'wa_conversation', $b->conversation_id, null,
                ['block_id' => $b->id, 'reblock' => true, 'reason_code' => $b->reason_code], AuditSeverity::Warn);
        });

        return ['ok' => true, 'code' => null, 'error' => null];
    }

    /**
     * «تسجيل سبب» لحظر موجود عند ميتا بلا سجل عندنا (نتيجة المزامنة): لا نداء لميتا، صف meta_sync بسبب يكتبه المدير.
     *
     * @param  array{phone?: string, user_id?: string}  $entry  من نتيجة المزامنة المحفوظة في الخادم، لا من المتصفح
     */
    public static function registerUntracked(array $entry, User $u, string $code, string $reason): array
    {
        if (!array_key_exists($code, self::REASONS)) {
            return self::refuse('BLOCK_REASON', 'اختر سبب الحظر.');
        }
        $reason = self::cleanReason($reason);
        if (mb_strlen($reason) < self::REASON_MIN || mb_strlen($reason) > self::REASON_MAX) {
            return self::refuse('BLOCK_REASON', 'اكتب تفاصيل السبب من '.self::REASON_MIN.' إلى '.self::REASON_MAX.' حرف.');
        }
        $phone = isset($entry['phone']) ? PhoneNormalizer::digits($entry['phone']) : null;
        $uid = $entry['user_id'] ?? null;
        $tracked = WaBlock::active()->where(fn ($q) => $q->when($phone, fn ($w) => $w->orWhere('phone', $phone))->when($uid, fn ($w) => $w->orWhere('wa_user_id', $uid)))->exists();
        if ($tracked || (!$phone && !$uid)) {
            return self::refuse('ALREADY_TRACKED', 'لهذا الرقم حظر مسجّل عندنا.');
        }
        $conv = WaConversation::query()->whereNull('erased_at')
            ->where(fn ($q) => $q->when($phone, fn ($w) => $w->orWhere('phone', $phone))->when($uid, fn ($w) => $w->orWhere('wa_user_id', $uid)))
            ->where('status', '!=', 'merged')->orderByDesc('id')->first();

        $block = DB::transaction(function () use ($conv, $phone, $uid, $u, $code, $reason) {
            $block = WaBlock::create([
                'conversation_id' => $conv?->id, 'phone' => $phone ?: null, 'wa_user_id' => $uid ?: ($conv?->wa_user_id ?: null),
                'reason_code' => $code, 'reason' => $reason, 'blocked_by' => $u->id, 'blocked_via' => 'meta_sync',
                'blocked_at' => now(), 'meta_state' => 'blocked', 'meta_checked_at' => now(),
            ]);
            if ($conv && !$conv->isBlocked()) {
                self::markConversation($conv);
            }
            AuditLog::log(AuditAction::WaBlock, 'wa_conversation', $conv?->id, null,
                ['reason_code' => $code, 'reason_len' => mb_strlen($reason), 'via' => 'meta_sync', 'block_id' => $block->id], AuditSeverity::Warn);

            return $block;
        });

        return ['ok' => true, 'code' => null, 'error' => null, 'block_id' => $block->id];
    }

    /** فك حظر موجود عند ميتا بلا سجل عندنا (نتيجة المزامنة)، بسبب إلزامي */
    public static function unblockUntracked(array $entry, User $u, string $reason): array
    {
        $reason = self::cleanReason($reason);
        if (mb_strlen($reason) < self::UNBLOCK_REASON_MIN || mb_strlen($reason) > self::REASON_MAX) {
            return self::refuse('UNBLOCK_REASON', 'اكتب سبب فك الحظر من '.self::UNBLOCK_REASON_MIN.' إلى '.self::REASON_MAX.' حرف.');
        }
        $target = isset($entry['phone']) ? ['user' => '+'.PhoneNormalizer::digits($entry['phone'])] : ['user_id' => (string) ($entry['user_id'] ?? '')];
        $r = WhatsAppChannel::unblockUsers($target);
        if (!$r['ok']) {
            AuditLog::safe(AuditAction::WaBlockFailed, 'wa_block', null, null, ['op' => 'unblock_untracked', 'code' => $r['code'], 'meta_code' => $r['meta_code'] ?? null]);

            return $r;
        }
        AuditLog::log(AuditAction::WaUnblock, 'wa_block', null, null, ['via' => 'meta_sync', 'reason_len' => mb_strlen($reason)], AuditSeverity::Warn);

        return ['ok' => true, 'code' => null, 'error' => null];
    }

    /**
     * وارد من محادثة محظورة (لا يُفترض رسمياً): يُخزَّن كي لا يضيع، ولا يصل البوت، والحظر يصير mismatch فيظهر
     * في صفحة المزامنة. يكشف حظراً سقط عند ميتا أو فُكّ من خارج النظام.
     */
    public static function inboundWhileBlocked(WaConversation $c): void
    {
        WaLog::warning('wa.block.inbound_while_blocked', ['conv_id' => $c->id]);
        if (self::available()) {
            WaBlock::active()->where('conversation_id', $c->id)->where('meta_state', '!=', 'mismatch')->update(['meta_state' => 'mismatch', 'updated_at' => now()]);
        }
    }

    /**
     * «حذف بياناتي» (E13 whatsapp:forget): الحظر يبقى (مصلحة مشروعة ضد الاحتيال، والحظر قائم عند ميتا أصلاً)
     * لكن مجرّداً: بصمة الرقم أو المعرّف بدلهما، وبلا نص السبب، وبلا ربط بالمحادثة. فك الحظر لاحقاً يبقى ممكناً من
     * المزامنة، لأن ميتا تعيد الرقم في قائمتها فيُطابَق بالبصمة.
     *
     * @param  list<int>  $conversationIds
     */
    public static function stripForErasure(array $conversationIds, ?string $canonicalPhone, array $userIds = []): int
    {
        if (!Schema::hasTable('wa_blocks')) {
            return 0;
        }
        $rows = WaBlock::query()->whereNull('erased_at')->where(function ($q) use ($conversationIds, $canonicalPhone, $userIds) {
            $q->whereIn('conversation_id', $conversationIds ?: [0]);
            if ($canonicalPhone) {
                $q->orWhere('phone', $canonicalPhone);
            }
            if ($userIds) {
                $q->orWhereIn('wa_user_id', $userIds);
            }
        })->get();

        foreach ($rows as $b) {
            $hash = match (true) {
                filled($b->phone) => Retention::subjectHmac(PhoneNormalizer::canonical($b->phone) ?: PhoneNormalizer::digits($b->phone)),
                filled($b->wa_user_id) => Retention::userIdHmac($b->wa_user_id),
                default => $b->subject_hash,
            };
            $b->forceFill([
                'subject_hash' => $hash, 'phone' => null, 'wa_user_id' => null, 'conversation_id' => null,
                'reason' => self::ERASED_REASON, 'unblock_reason' => $b->unblock_reason ? self::ERASED_REASON : null, 'erased_at' => now(),
            ])->save();
        }

        return $rows->count();
    }

    public static function reasonLabel(?string $code): string
    {
        return self::REASONS[$code] ?? (string) $code;
    }

    /** ما يحدث للمحادثة: محظورة، والبوت مطفأ لها، ولا «تدخّل» ولا إيقاف مؤقت */
    private static function markConversation(WaConversation $c): void
    {
        $c->forceFill(['blocked_at' => now(), 'bot_enabled' => false, 'needs_attention' => false, 'bot_paused_until' => null]
            + (ReplyState::available() ? ['attention_reason' => null] : []))->save();
    }

    private static function refusal(WaConversation $c): ?array
    {
        return match (true) {
            (bool) $c->is_admin_chat || ($c->hasPhone() && WaAdminNumber::forPhone($c->phone) !== null) => self::refuse('BLOCK_ADMIN', 'لا يُحظر رقم إدارة.'),
            (bool) $c->user_id => self::refuse('BLOCK_STAFF', 'هذا الرقم لموظف في الشركة، فلا يُحظر.'),
            $c->isBlocked() => self::refuse('ALREADY_BLOCKED', 'هذه المحادثة محظورة أصلاً.'),
            $c->status === 'merged' => self::refuse('BLOCK_MERGED', 'محادثة مدموجة في أخرى: احظر من المحادثة الأصلية.'),
            $c->erased_at !== null => self::refuse('BLOCK_ERASED', 'محادثة حُذفت بياناتها.'),
            default => null,
        };
    }

    /** نص يضعه الموظف: بلا محارف تحكم ولا اتجاه، ومسافات موحّدة (لا قص هنا: الطويل يُرفض لا يُقص) */
    private static function cleanReason(string $reason): string
    {
        return (string) TextNormalizer::untrustedName($reason, 2000);
    }

    /** المدراء بلا رقم ولا اسم عميل (قرار المالك: يمكن إطفاؤه بـ WA_BLOCK_NOTIFY=false) */
    private static function notify(WaConversation $c, ?User $u, ?WaAdminNumber $a, string $code): void
    {
        if (!config('whatsapp.block.notify_admins', true)) {
            return;
        }
        $who = $u?->name ?: ($a ? 'رقم الإدارة «'.$a->name.'»' : 'النظام');
        WhatsAppInbox::notifyAdmins('⛔ حُظر رقم على واتساب',
            "حظر {$who} محادثة #{$c->id} — السبب: ".self::reasonLabel($code).'. يمكن فك الحظر من صفحة المحظورين.', '/whatsapp/blocks');
    }

    private static function refuse(string $code, string $error): array
    {
        return ['ok' => false, 'code' => $code, 'error' => $error, 'meta_code' => null];
    }
}
