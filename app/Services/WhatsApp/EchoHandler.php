<?php

namespace App\Services\WhatsApp;

use App\Events\WhatsApp\WaMessageSent;
use App\Models\User;
use App\Models\WaBotSetting;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\Ops\OpsAlerts;
use App\Services\Ops\OpsHealth;
use App\Support\FeatureFlags;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * أصداء التعايش (E39، الحقل smb_message_echoes): ما أرسله الموظف من تطبيق WhatsApp Business على هاتف الوكالة.
 * بلا العلمين سلوك E20 (تنبيه فوري ولا شيء آخر). بعد السياج وفحص metadata.phone_number_id، فلا أصداء رقم آخر.
 *
 * - الحارس المبكر (coexistence_guard، يصلح للشحن قبل القرار): المحادثة المطابقة بـ to (بلا إنشاء) يُوقف بوتها فوراً
 *   مدة لا تقل عن ساعة، وتُرفع عنها «تدخّل»، وتنبيه للمدراء مرة في اليوم. لا تخزين للمحتوى.
 * - المعالجة الكاملة (coexistence_echoes): كل صدى صف صادر بالمصدر staff_phone وwamid فريد (المكرر نجاح)، والحامل
 *   مرسلاً، ووسائطه إلى wa_media كالوارد (E14). يحدّث last_outbound_at والمعاينة ولا يمس last_inbound_at (الصدى لا يفتح
 *   النافذة)، ويوقف البوت كرد الموظف، فيُسقط ReplyRun أي رد قيد التوليد (stale_takeover). التعديل والحذف لا يمسان الأصل:
 *   علامة في حمولته، والنص المعدّل صف جديد msg_type=edit.
 * الأصداء لا تمر بـ WhatsAppChannel: أُرسلت فعلاً من الهاتف، فلا أقفال ولا OutboundPolicy ولا دفتر عليها.
 */
final class EchoHandler
{
    private const UNSTORED_REVOKE = '🗑️ حُذفت من الهاتف رسالة لم تُحفظ هنا';

    public static function guardOn(): bool
    {
        return FeatureFlags::on('coexistence_guard');
    }

    public static function fullOn(): bool
    {
        return FeatureFlags::on('coexistence_echoes');
    }

    public static function active(): bool
    {
        return self::fullOn() || self::guardOn();
    }

    public static function handle(array $value, string $ownId, WebhookResult $result): void
    {
        WebhookFields::seen('smb_message_echoes');
        $echoes = (array) data_get($value, 'message_echoes', []);
        $pnid = (string) data_get($value, 'metadata.phone_number_id', '');
        if ($ownId === '' || $pnid !== $ownId) {
            WaLog::warning('coexistence.echo_foreign', ['count' => count($echoes)]);

            return;
        }

        $full = self::fullOn();
        foreach ($echoes as $i => $echo) {
            try {
                $full ? self::store((array) $echo, $pnid) : self::guard((array) $echo, $pnid);
            } catch (\Throwable $e) {
                $ref = is_string(data_get($echo, 'id')) ? (string) data_get($echo, 'id') : "echo.{$i}";
                $result->fail('echo', $ref, $e);
                WaLog::error('webhook.item_failed', ['field' => 'smb_message_echoes', 'wamid' => $ref, 'exception' => $e]);
            }
        }
        if (!$full && $echoes !== []) {
            self::guardAlert();
        }
    }

    // ==================== الحارس المبكر ====================

    private static function guard(array $echo, string $pnid): void
    {
        $in = InboundMessage::fromEcho($echo, $pnid);
        $conv = ContactIdentity::find($in->fromPhone, $in->fromUserId);
        if ($conv === null) {
            WaLog::info('coexistence.echo_unmatched', ['mode' => 'guard', 'wamid' => $in->wamid]);

            return;
        }
        $s = WaBotSetting::current();
        $minutes = max((int) $s->pause_minutes, (int) config('whatsapp.coexistence.guard_pause_minutes', 60));
        self::pause($conv, now()->addMinutes($minutes));
        WaLog::info('coexistence.echo', ['mode' => 'guard', 'conv_id' => $conv->id]);
    }

    /** مرة في اليوم: الأصداء تصل والمعالجة الكاملة مطفأة (لا يُحفظ ما كتبه الموظف من الهاتف) */
    private static function guardAlert(): void
    {
        if (!OpsHealth::store()->add('wa:coex:guard:alerted:'.now()->format('Ymd'), 1, now()->addDays(2))) {
            return;
        }
        app(OpsAlerts::class)->notifyAdmins('wa:coexistence:guard', '📱 وصلت رسائل من تطبيق واتساب أعمال — التعايش مفعّل دون إعداد كامل',
            'أُوقف البوت في المحادثات التي كتب فيها الموظف من الهاتف، ولم يُحفظ نص تلك الرسائل. راجع ربط الرقم والقرار المكتوب قبل أي رد آلي.');
    }

    // ==================== المعالجة الكاملة ====================

    private static function store(array $echo, string $pnid): void
    {
        $type = (string) (data_get($echo, 'type') ?: 'text');
        if ($type === 'edit' || $type === 'revoke') {
            self::change($echo, $type, $pnid);

            return;
        }

        $in = InboundMessage::fromEcho($echo, $pnid);
        if (!$in->wamid || ($in->fromPhone === null && $in->fromUserId === null)) {
            WaLog::warning('coexistence.echo_incomplete', ['wamid' => $in->wamid, 'type' => $type]);

            return;
        }
        if (WaMessage::where('wamid', $in->wamid)->exists()) {
            return;
        }
        $at = self::time(data_get($echo, 'timestamp'));
        $holder = self::holderId();

        try {
            [$conv, $message] = DB::transaction(function () use ($in, $echo, $at, $holder) {
                // الموظف قد يبدأ المحادثة من الهاتف: تُنشأ إن لم توجد
                $conv = ContactIdentity::resolve($in);
                $message = WaMessage::create([
                    'conversation_id' => $conv->id,
                    'wamid' => $in->wamid,
                    'direction' => 'out',
                    'source' => 'staff_phone',
                    'msg_type' => $in->type,
                    'body' => $in->body,
                    'payload' => $echo + ['_echo' => true],
                    'status' => 'sent',
                    'staff_id' => $holder,
                ] + self::columns($in, $at));
                self::afterEcho($conv, $at, $in->body);
                // وسائط الصدى كالوارد تماماً (E14): العلم media_archive، والجدولة بعد الاعتماد
                Media\MediaIntake::onInbound($message, $conv, $echo);

                return [$conv, $message];
            });
        } catch (UniqueConstraintViolationException) {
            // نسختان من الصدى نفسه معاً: الأخرى خزّنته
            return;
        }
        EventBus::emit(new WaMessageSent($message->id, $conv->id, 'staff_phone', $holder));
        WaLog::info('coexistence.echo', ['mode' => 'full', 'conv_id' => $conv->id, 'message_id' => $message->id, 'type' => $in->type]);
    }

    /**
     * تعديل أو حذف من الهاتف: الأصل لا يُحذف ولا يتغير نصه (دليل عند النزاع). علامة _edited_at أو _revoked_at في حمولته،
     * والنص الجديد صف msg_type=edit يقتبس الأصل. حذف رسالة لم تُحفظ عندنا صف msg_type=revoke بنص ثابت.
     */
    private static function change(array $echo, string $type, string $pnid): void
    {
        $wamid = is_string(data_get($echo, 'id')) ? mb_substr((string) data_get($echo, 'id'), 0, 128) : null;
        if ($wamid === null || WaMessage::where('wamid', $wamid)->exists()) {
            return;
        }
        $node = (array) data_get($echo, $type, []);
        $originalId = data_get($node, 'original_message_id') ?? data_get($node, 'message_id') ?? data_get($echo, 'context.id');
        $originalId = is_string($originalId) && $originalId !== '' ? mb_substr($originalId, 0, 128) : null;
        $at = self::time(data_get($echo, 'timestamp'));
        $holder = self::holderId();

        $stored = DB::transaction(function () use ($echo, $type, $pnid, $node, $originalId, $wamid, $at, $holder) {
            $original = $originalId !== null ? WaMessage::where('wamid', $originalId)->lockForUpdate()->first() : null;
            if ($original !== null) {
                $payload = (array) ($original->payload ?? []);
                $payload[$type === 'edit' ? '_edited_at' : '_revoked_at'] ??= $at->toIso8601String();
                $original->forceFill(['payload' => $payload])->save();
            }
            if ($type === 'revoke' && $original !== null) {
                $conv = self::live((int) $original->conversation_id);
                if ($conv !== null) {
                    self::pause($conv, self::echoPauseUntil());
                }

                return null;
            }

            $conv = $original !== null ? self::live((int) $original->conversation_id) : null;
            $in = InboundMessage::fromEcho($echo, $pnid);
            if ($conv === null) {
                if ($in->fromPhone === null && $in->fromUserId === null) {
                    return null;
                }
                $conv = ContactIdentity::resolve($in);
            }
            $body = $type === 'edit' ? self::editedBody($echo, $node, $pnid) : self::UNSTORED_REVOKE;
            $message = WaMessage::create([
                'conversation_id' => $conv->id,
                'wamid' => $wamid,
                'direction' => 'out',
                'source' => 'staff_phone',
                'msg_type' => $type,
                'body' => $body,
                'payload' => ['_echo' => true, '_'.$type.'_of' => $originalId],
                'status' => 'sent',
                'staff_id' => $holder,
            ] + (self::hasColumns() ? array_filter(['reply_to_wamid' => $original?->wamid, 'phone_number_id' => $pnid]) : []));
            self::afterEcho($conv, $at, $type === 'edit' ? '✏️ '.$body : $body);

            return [$conv, $message];
        });

        if (is_array($stored)) {
            EventBus::emit(new WaMessageSent($stored[1]->id, $stored[0]->id, 'staff_phone', $holder));
        }
        WaLog::info('coexistence.echo', ['mode' => 'full', 'type' => $type, 'event' => $stored === null ? 'flagged' : 'stored']);
    }

    /** النص الجديد للتعديل: رسالة كاملة في edit.message، أو نص مباشر، بالمستخرج نفسه */
    private static function editedBody(array $echo, array $node, string $pnid): string
    {
        $inner = data_get($node, 'message');
        if (is_array($inner) && filled($inner['type'] ?? null)) {
            return InboundMessage::fromEcho($inner + ['to' => data_get($echo, 'to')], $pnid)->body;
        }
        $text = data_get($node, 'text.body') ?? data_get($node, 'body') ?? data_get($echo, 'text.body');

        return is_string($text) ? $text : '✏️ عُدّلت من الهاتف';
    }

    /** كرد الموظف من الصندوق: إيقاف البوت، وتصفير المؤشرات، والعلامة إلى أعلى وارد. المؤشرات رتيبة ولا تمس النافذة */
    private static function afterEcho(WaConversation $conv, Carbon $at, string $preview): void
    {
        $newer = $conv->last_message_at === null || $conv->last_message_at->lte($at);
        $conv->forceFill(array_filter([
            'last_outbound_at' => $conv->last_outbound_at === null || $conv->last_outbound_at->lt($at) ? $at : null,
            'last_message_at' => $newer ? $at : null,
            'last_message_preview' => $newer ? mb_substr(trim($preview), 0, 160) : null,
        ], fn ($v) => $v !== null) + ['unread_count' => 0]
            + (InboxFollowup::available() ? ['marked_unread_at' => null, 'marked_unread_by' => null] : []));
        self::pause($conv, self::echoPauseUntil());
    }

    private static function echoPauseUntil(): Carbon
    {
        $s = WaBotSetting::current();

        return now()->addMinutes(max(1, (int) ($s->echo_pause_minutes ?: $s->pause_minutes)));
    }

    /** الإيقاف لا يقصّر إيقافاً أطول قائماً، ويرفع «تدخّل»: موظف يتابع الآن */
    private static function pause(WaConversation $conv, Carbon $until): void
    {
        $current = $conv->bot_paused_until;
        $conv->forceFill([
            'bot_paused_until' => $current !== null && $current->gt($until) ? $current : $until,
            'needs_attention' => false,
        ] + (ReplyState::available() ? ['attention_reason' => null] : []))->save();
        ReplyState::advanceToNewest($conv);
    }

    /** حامل جهاز التعايش إن كان نشطاً ويملك whatsapp.view_all الآن، وإلا بلا مرسل */
    public static function holderId(): ?int
    {
        $id = (int) WaBotSetting::current()->coexistence_holder_user_id;
        if ($id <= 0) {
            return null;
        }
        $user = User::where('is_active', true)->find($id);

        return $user !== null && $user->can('whatsapp.view_all') ? $id : null;
    }

    private static function live(int $convId): ?WaConversation
    {
        $conv = WaConversation::find($convId);
        if ($conv && $conv->status === 'merged' && $conv->merged_into_id) {
            return WaConversation::find($conv->merged_into_id) ?? $conv;
        }

        return $conv;
    }

    private static ?bool $hasColumns = null;

    private static ?bool $hasSentAt = null;

    private static function hasColumns(): bool
    {
        return self::$hasColumns ??= Schema::hasColumns('wa_messages', ['reply_to_wamid', 'media_id', 'phone_number_id']);
    }

    /** رقمنا والاقتباس والوسائط (كأعمدة الوارد)، ووقت القبول sent_at */
    private static function columns(InboundMessage $in, Carbon $at): array
    {
        $out = self::hasColumns() ? array_filter([
            'phone_number_id' => $in->phoneNumberId ?: null,
            'reply_to_wamid' => $in->replyToWamid,
            'media_id' => $in->media['id'] ?? null,
            'media_mime' => $in->media['mime'] ?? null,
            'media_sha256' => $in->media['sha256'] ?? null,
            'media_filename' => $in->media['filename'] ?? null,
        ], fn ($v) => $v !== null) : [];

        if (self::$hasSentAt ??= Schema::hasColumn('wa_messages', 'sent_at')) {
            $out['sent_at'] = $at;
        }

        return $out;
    }

    /** وقت ميتا للصدى، لا يتجاوز «الآن» */
    private static function time(mixed $ts): Carbon
    {
        return is_numeric($ts) && (int) $ts > 0 ? min(Carbon::createFromTimestamp((int) $ts, config('app.timezone')), now()) : now();
    }
}
