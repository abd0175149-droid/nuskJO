<?php

namespace App\Services\WhatsApp;

use App\Enums\AuditAction;
use App\Enums\HealthWriter;
use App\Enums\WaHealth;
use App\Events\WhatsApp\ChannelHealthChanged;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\WaAccountEvent;
use App\Models\WaBotSetting;
use App\Services\Ops\OpsAlerts;
use App\Services\Ops\OpsHealth;
use App\Services\WhatsApp\Settings\SettingsWriter;
use App\Support\AuditActor;
use App\Support\FeatureFlags;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * صحة قناة الإرسال كآلة حالات (E22): AVAILABLE وLIMITED وBLOCKED وTOKEN_INVALID وUNKNOWN في wa_bot_settings.wa_health،
 * من مصادر ميتا الرسمية وحدها: ويبهوكات الحساب (AccountEventHandler)، وhealth_status (fromPoll)، ورموز أخطاء الإرسال
 * (SendHealthMonitor)، وdebug_token. الإيقاف اليدوي (wa_suspended) محور مستقل لا يكتبه أي مسار آلي بعد تفعيل العلم.
 *
 * - transition: كتابة مشروطة WHERE wa_health = من (SettingsWriter::applyIf) بجدول ALLOWED لمالك كل انتقال، وما خارجه
 *   يُرفض ويُسجَّل بلا أثر. والانتقال الفعلي وحده: حدث ChannelHealthChanged مرة واحدة، وتدقيق، وتنبيه داخل النظام وFCM.
 * - لا رفع آلياً لأي قفل: REINSTATE وفحص يعيد AVAILABLE ومرور الانتهاء تضع «اقتراح رفع» وتنبّه، ويرفع المدير بزر وسبب.
 * - هذا الصنف وحده يطلق ChannelHealthChanged (ومعه الإيقاف الآلي القديم والمفتاح اليدوي)، فكل تغيّر يُطلق مرة واحدة.
 *
 * العلم channel_health مطفأ: لا انتقال ولا اقتراح (الإيقاف الآلي القديم في wa_suspended كما هو)، لكن BLOCKED قائم
 * (نقلته الهجرة أو بقي بعد إطفاء العلم) يمنع الإرسال دائماً ولا يُرفع إلا يدوياً.
 */
final class ChannelHealth
{
    public const URL = '/whatsapp/settings#channel-health';

    /** من => إلى => الكتّاب. ومن TOKEN_INVALID بالفحص يحتاج إنساناً بدأه (حفظ رمز جديد أو «افحص الآن») */
    public const ALLOWED = [
        'UNKNOWN' => [
            'AVAILABLE' => ['poll'],
            'LIMITED' => ['webhook', 'poll'],
            'BLOCKED' => ['webhook', 'poll', 'send_error'],
            'TOKEN_INVALID' => ['send_error', 'poll', 'token_check'],
        ],
        'AVAILABLE' => [
            'LIMITED' => ['webhook', 'poll'],
            'BLOCKED' => ['webhook', 'poll', 'send_error'],
            'TOKEN_INVALID' => ['send_error', 'poll', 'token_check'],
        ],
        'LIMITED' => [
            'AVAILABLE' => ['poll'],
            'BLOCKED' => ['webhook', 'poll', 'send_error'],
            'TOKEN_INVALID' => ['send_error', 'poll', 'token_check'],
        ],
        'TOKEN_INVALID' => [
            'AVAILABLE' => ['poll'],
            'LIMITED' => ['poll'],
            'BLOCKED' => ['webhook', 'poll', 'send_error'],
        ],
        // BLOCKED أشد من الرمز الباطل: لا ينزل إلى TOKEN_INVALID ولا إلى LIMITED آلياً، ويخرج بالمدير وحده
        'BLOCKED' => [
            'AVAILABLE' => ['manual'],
            'LIMITED' => ['manual'],
        ],
    ];

    /** مفاتيح wa_health_meta المعروضة (لا معرّفات كيانات ولا رموز) */
    private const META_KEYS = ['quality_rating', 'tier', 'last_poll', 'possible_solution', 'country_restricted', 'waba_match',
        'restriction_types', 'violation_type', 'token_while_blocked', 'unblock_why', 'unblock_at', 'last_reason'];

    private static ?bool $available = null;

    /** أعمدة الهجرة 2026_10_14_000022 موجودة (بين النشر والهجرة: لا حالة ولا انتقال، والبوابة كما كانت) */
    public static function available(): bool
    {
        return self::$available ??= Schema::hasColumn('wa_bot_settings', 'wa_health');
    }

    /** العلم channel_health والأعمدة: الآلة تكتب، والإيقاف الآلي القديم متوقف */
    public static function enabled(): bool
    {
        return self::available() && FeatureFlags::on('channel_health');
    }

    public static function current(?WaBotSetting $s = null): WaHealth
    {
        return WaHealth::of(($s ?? WaBotSetting::current())->wa_health);
    }

    /** BLOCKED دائماً، وTOKEN_INVALID بالعلم channel_health_block_on_token. في الوضعين: قفل قائم لا يُتجاهل */
    public static function blocksSending(?WaBotSetting $s = null): bool
    {
        return self::current($s)->blocksSending();
    }

    /**
     * رمز الخطأ ورسالته للبوابة (WhatsAppChannel::gate)، أو null إن لم تمنع الحالة الإرسال. بلا تفاصيل ميتا:
     * الموظف يرى أن القناة محجوبة، والتفاصيل في بطاقة «صحة القناة» لمن يملك whatsapp.settings.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function gateError(WaBotSetting $s): ?array
    {
        $health = self::current($s);
        if (!$health->blocksSending()) {
            return null;
        }
        if ($health === WaHealth::TokenInvalid) {
            return [WhatsAppChannel::ERR_TOKEN, 'رمز ميتا غير صالح — الإرسال متوقف حتى يجدّده المدير.'];
        }
        $until = $s->wa_health_until;

        return [WhatsAppChannel::ERR_CHANNEL_BLOCKED, 'ميتا تحجب الإرسال من رقم الشركة'.($until !== null && $until->isFuture() ? ' حتى '.self::date($until) : '').' — أبلغ المدير.'];
    }

    // ==================== الانتقالات ====================

    public static function allows(WaHealth $from, WaHealth $to, HealthWriter $by, bool $humanInitiated = false): bool
    {
        if (!in_array($by->value, self::ALLOWED[$from->value][$to->value] ?? [], true)) {
            return false;
        }

        return !($from === WaHealth::TokenInvalid && $by === HealthWriter::Poll && $to !== WaHealth::Blocked && !$humanInitiated);
    }

    /**
     * انتقال واحد. $code رمز داخلي بلا نص (ban:DISABLE، integrity:368، restriction:…، token:190، manual) يُحمل في الحدث،
     * و$note نص قصير للبطاقة (سبب المدير أو وصف ميتا). يعيد true للانتقال الفعلي وحده.
     */
    public static function transition(WaHealth $to, HealthWriter $by, string $code, ?string $note = null, ?Carbon $until = null,
        bool $humanInitiated = false, array $meta = []): bool
    {
        if (!self::available() || ($by !== HealthWriter::Manual && !self::enabled())) {
            return false;
        }

        // صف لم يطابق (كتب غيرنا بين القراءة والكتابة): تُعاد القراءة ويُقيَّم الانتقال مرة واحدة
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $s = WaBotSetting::current();
            $from = self::current($s);

            if ($from === $to) {
                self::refresh($s, $to, $code, $until, $meta);

                return false;
            }
            if (!self::allows($from, $to, $by, $humanInitiated)) {
                // الرمز الباطل والقناة محجوبة: يُذكر في الملخص فقط (BLOCKED أشد)
                if ($from === WaHealth::Blocked && $to === WaHealth::TokenInvalid) {
                    self::remember(['token_while_blocked' => $code]);
                }
                WaLog::info('health.transition_refused', ['from' => $from->value, 'to' => $to->value, 'writer' => $by->value, 'reason' => $code]);

                return false;
            }

            $changes = [
                'wa_health' => $to->value,
                'wa_health_reason' => mb_substr($code.($note !== null && $note !== '' ? ' — '.$note : ''), 0, 255),
                'wa_health_source' => $by->value,
                'wa_health_until' => $until,
                'wa_health_changed_at' => now(),
                'wa_health_unblock_suggested' => false,
            ];
            if ($meta !== []) {
                $changes['wa_health_meta'] = self::mergedMeta($s, $meta);
            }
            if (SettingsWriter::applyIf(['wa_health' => $from->value], $changes)) {
                self::after($from, $to, $by, $code, $note, $until);

                return true;
            }
        }

        return false;
    }

    public static function fromWebhook(WaHealth $to, string $code, ?Carbon $until = null, ?string $note = null, array $meta = []): bool
    {
        return self::transition($to, HealthWriter::Webhook, $code, $note, $until, false, $meta);
    }

    public static function fromSendError(WaHealth $to, string $code, ?string $note = null): bool
    {
        return self::transition($to, HealthWriter::SendError, $code, $note);
    }

    /** debug_token يقول إن الرمز باطل (E08) */
    public static function fromTokenCheck(): bool
    {
        return self::transition(WaHealth::TokenInvalid, HealthWriter::TokenCheck, 'token:debug_token', 'فحص الرمز اليومي: is_valid=false');
    }

    /**
     * نتيجة health_status (MetaHealth::channel). الفحص مرجع الحالة: AVAILABLE وLIMITED آلياً من غير القفل، وBLOCKED من أي حالة.
     * والقفل (BLOCKED، أو TOKEN_INVALID بلا إنسان) لا يُرفع هنا: اقتراح رفع وتنبيه. فشل الشبكة بلا انتقال (MetaHealth ينبّه).
     */
    public static function fromPoll(array $snapshot, bool $humanInitiated = false): void
    {
        if (!self::enabled() || in_array($snapshot['state'] ?? null, [null, 'not_configured'], true)) {
            return;
        }
        $can = $snapshot['can_send_message'] ?? null;
        if (!is_string($can) || $can === '') {
            $code = isset($snapshot['error_code']) ? (int) $snapshot['error_code'] : null;
            if ($code === 190 || $code === 0) {
                self::transition(WaHealth::TokenInvalid, HealthWriter::Poll, 'token:'.$code, 'ميتا رفضت الرمز في فحص صحة القناة');
            } elseif ($code === 10 || ($code !== null && $code >= 200 && $code <= 299)) {
                self::transition(WaHealth::TokenInvalid, HealthWriter::Poll, 'scope_missing', 'الرمز بلا صلاحية whatsapp_business_management');
            }

            return;
        }

        $current = self::current();
        $locked = $current === WaHealth::Blocked || ($current === WaHealth::TokenInvalid && !$humanInitiated);
        match ($can) {
            'AVAILABLE' => $locked
                ? self::suggestUnblock('فحص ميتا يعيد AVAILABLE', 'poll:AVAILABLE', !$humanInitiated)
                : self::transition(WaHealth::Available, HealthWriter::Poll, 'poll:AVAILABLE', null, null, $humanInitiated),
            'LIMITED' => $locked
                ? self::suggestUnblock('فحص ميتا يعيد LIMITED (قيد جزئي لا يمس الردود)', 'poll:LIMITED', !$humanInitiated)
                : self::transition(WaHealth::Limited, HealthWriter::Poll, 'poll:LIMITED', self::limitedNote($snapshot), null, $humanInitiated),
            'BLOCKED' => self::transition(WaHealth::Blocked, HealthWriter::Poll, ...self::blockedReason($snapshot)),
            default => null,
        };

        if (($snapshot['waba_match'] ?? null) === false) {
            app(OpsAlerts::class)->notifyAdmins('health:waba_mismatch', '⚠️ معرّف حساب الأعمال لا يطابق فحص ميتا',
                'معرّف WABA المضبوط أو المتعلَّم يختلف عن حساب الرقم في health_status. لا قفل؛ راجع WA_WABA_ID في الإعدادات.', self::URL);
        }
    }

    /** ما يُحفظ من كل فحص وصل إلى ميتا، في الوضعين (عرض فقط): وقته والجودة وآخر نتيجة والحل المقترح ومطابقة الحساب */
    public static function recordPoll(array $snapshot): void
    {
        if (!self::available() || in_array($snapshot['state'] ?? null, [null, 'not_configured'], true)
            || (($snapshot['state'] ?? null) === 'unreachable' && !isset($snapshot['error_code']))) {
            return;
        }
        $solution = null;
        foreach ((array) ($snapshot['entities'] ?? []) as $entity) {
            foreach ((array) ($entity['errors'] ?? []) as $err) {
                $solution ??= is_string($err['possible_solution'] ?? null) ? mb_substr($err['possible_solution'], 0, 300) : null;
            }
        }
        $meta = [
            'quality_rating' => $snapshot['quality_rating'] ?? null,
            'last_poll' => array_filter(['can_send_message' => $snapshot['can_send_message'] ?? null, 'error_code' => $snapshot['error_code'] ?? null,
                'at' => now()->toIso8601String()], fn ($v) => $v !== null),
            'possible_solution' => $solution,
        ];
        if (array_key_exists('waba_match', $snapshot)) {
            $meta['waba_match'] = $snapshot['waba_match'];
        }
        $s = WaBotSetting::current();
        SettingsWriter::applyIf([], ['wa_health_checked_at' => now(), 'wa_health_meta' => self::mergedMeta($s, $meta)], bumpVersion: false);
    }

    /**
     * «ميتا تشير إلى رفع القيد»: علامة وتنبيه مرة واحدة حين تتبدّل من لا إلى نعم، والقفل باقٍ. المدير يرفعه بزر وسبب
     * (BLOCKED)، أو «افحص الآن» بعد تجديد الرمز (TOKEN_INVALID). فحص بدأه المدير نفسه يضع العلامة بلا تنبيه.
     */
    public static function suggestUnblock(string $why, string $code, bool $notify = true): void
    {
        if (!self::enabled()) {
            return;
        }
        $s = WaBotSetting::current();
        $state = self::current($s);
        if (!in_array($state, [WaHealth::Blocked, WaHealth::TokenInvalid], true)) {
            return;
        }
        $changed = SettingsWriter::applyIf(['wa_health' => $state->value, 'wa_health_unblock_suggested' => false], [
            'wa_health_unblock_suggested' => true,
            'wa_health_meta' => self::mergedMeta($s, ['unblock_why' => mb_substr($code, 0, 40), 'unblock_at' => now()->toIso8601String()]),
        ], bumpVersion: false);
        if (!$changed) {
            return;
        }
        WaLog::warning('health.unblock_suggested', ['from' => $state->value, 'reason' => $code]);
        if (!$notify) {
            return;
        }
        [$title, $body] = $state === WaHealth::Blocked
            ? ['✅ ميتا تشير إلى رفع القيد — راجع ثم ارفع القفل يدوياً', "{$why}. القفل باقٍ حتى يرفعه المدير من بطاقة «صحة القناة» بسبب."]
            : ['✅ رمز ميتا يعمل الآن — اضغط «افحص الآن»', "{$why}. الحالة تبقى «رمز غير صالح» حتى يفحص المدير من بطاقة «صحة القناة»."];
        app(OpsAlerts::class)->notifyAdmins('health:suggest:'.$state->value, $title, $body, self::URL);
    }

    /** المدير اطّلع على الاقتراح: تُطفأ العلامة وحدها، والقفل كما هو */
    public static function acknowledge(): bool
    {
        return self::available() && SettingsWriter::applyIf(['wa_health_unblock_suggested' => true], ['wa_health_unblock_suggested' => false], bumpVersion: false);
    }

    /** دمج مفاتيح في wa_health_meta (بلا رفع النسخة) */
    public static function remember(array $meta): void
    {
        if (!self::available() || $meta === []) {
            return;
        }
        SettingsWriter::applyIf([], ['wa_health_meta' => self::mergedMeta(WaBotSetting::current(), $meta)], bumpVersion: false);
    }

    // ==================== الإيقاف القديم والمفتاح اليدوي ====================

    /**
     * الإيقاف الآلي القديم (العلم channel_health مطفأ): wa_suspended بمصدر meta_event أو graph_error، كما كان قبل E22،
     * مع تدقيق آمن WaSuspendAuto. القفل وحده: الإشعار عند المستدعي. يعيد false إن كان الإرسال موقوفاً أصلاً.
     */
    public static function suspendLegacy(string $source, string $reason, string $reasonCode, array $auditMeta, string $actorType, string $actorRef, string $label): bool
    {
        if (WaBotSetting::current()->wa_suspended) {
            return false;
        }
        // يرفع النسخة: صفحة إعدادات فُتحت قبله لا تلغيه بحفظها (409). وفشل التدقيق لا يُسقط الإيقاف (safe)
        AuditActor::as($actorType, $actorRef, fn () => SettingsWriter::apply([
            'wa_suspended' => true, 'wa_suspended_at' => now(), 'wa_suspended_by' => null,
            'wa_suspended_source' => $source, 'wa_suspended_reason' => $reason,
        ], null, AuditAction::WaSuspendAuto, $auditMeta, audit: 'safe', label: $label));
        self::emit(ChannelHealthChanged::AVAILABLE, ChannelHealthChanged::SUSPENDED_AUTO, $source, mb_substr($reasonCode, 0, 40));

        return true;
    }

    /**
     * المفتاح اليدوي (E10) كتب wa_suspended للتو: الحدث وحده من هنا. الطرف الآخر حالة الآلة إن كانت مفعّلة، وإلا AVAILABLE.
     * والسبب نص المدير فلا يُحمل؛ عند الاستئناف رمز المصدر السابق.
     */
    public static function manualSwitched(bool $on, ?string $previousSource): void
    {
        $machine = self::enabled() ? self::current()->value : ChannelHealthChanged::AVAILABLE;
        $suspended = $previousSource === 'manual' ? ChannelHealthChanged::SUSPENDED_MANUAL : ChannelHealthChanged::SUSPENDED_AUTO;
        $on ? self::emit($machine, ChannelHealthChanged::SUSPENDED_MANUAL, 'manual', null)
            : self::emit($suspended, $machine, 'manual', $previousSource);
    }

    // ==================== العرض ====================

    /** للبطاقة ومسار GET (whatsapp.settings): بلا رمز ولا سر ولا معرّفات كيانات ولا حمولة خام */
    public static function panel(): array
    {
        $s = WaBotSetting::current();
        $health = self::current($s);
        $source = $s->wa_health_source;

        return [
            'enabled' => self::enabled(),
            'available' => self::available(),
            'state' => $health->value,
            'label' => $health->label(),
            'severity' => $health->severity(),
            'reason' => $s->wa_health_reason,
            'source' => $source,
            'source_label' => $source === HealthWriter::LEGACY ? 'قفل آلي سابق' : (HealthWriter::tryFrom((string) $source)?->label()),
            'until' => $s->wa_health_until?->toIso8601String(),
            'checked_at' => $s->wa_health_checked_at?->toIso8601String(),
            'changed_at' => $s->wa_health_changed_at?->toIso8601String(),
            'unblock_suggested' => (bool) $s->wa_health_unblock_suggested,
            'blocks' => $health->blocksSending(),
            'block_on_token' => FeatureFlags::on('channel_health_block_on_token'),
            'breaker_lock' => FeatureFlags::on('integrity_breaker_lock'),
            'meta' => array_intersect_key((array) ($s->wa_health_meta ?? []), array_flip(self::META_KEYS)),
            'consecutive_failures' => SendHealthMonitor::consecutive(),
            'last_error' => $s->wa_last_error,
            'manual' => [
                'on' => (bool) $s->wa_suspended,
                'source' => $s->suspendSource(),
                'reason' => $s->wa_suspended ? $s->wa_suspended_reason : null,
                'at' => $s->wa_suspended ? $s->wa_suspended_at?->toIso8601String() : null,
                'by_name' => $s->wa_suspended && $s->wa_suspended_by ? User::whereKey($s->wa_suspended_by)->value('name') : null,
            ],
            'foreign_today' => (int) OpsHealth::store()->get('wa:acct:foreign:'.now()->format('Ymd'), 0),
            'events' => rescue(fn () => WaAccountEvent::orderByDesc('id')->limit(30)
                ->get(['id', 'created_at', 'field', 'event', 'severity', 'effect', 'handled_at'])
                ->map(fn (WaAccountEvent $e) => [
                    'id' => $e->id, 'at' => $e->created_at?->toIso8601String(), 'field' => $e->field, 'event' => $e->event,
                    'severity' => $e->severity, 'effect' => $e->effect, 'handled' => $e->handled_at !== null,
                ])->all(), [], false),
        ];
    }

    /** لشريط الصندوق (whatsapp.view): الحالة والتسمية والانتهاء وهل تمنع، بلا سبب ولا رمز */
    public static function publicStatus(WaBotSetting $s): array
    {
        $health = self::current($s);

        return [
            'health' => $health->value,
            'health_label' => $health->label(),
            'health_until' => $s->wa_health_until?->toIso8601String(),
            'health_blocks' => $health->blocksSending(),
        ];
    }

    // ==================== داخلي ====================

    /** الحالة نفسها: الانتهاء الجديد إن وصل، والسبب الأحدث في الملخص. بلا حدث ولا تنبيه ولا رفع نسخة */
    private static function refresh(WaBotSetting $s, WaHealth $state, string $code, ?Carbon $until, array $meta): void
    {
        $changes = ['wa_health_meta' => self::mergedMeta($s, $meta + ['last_reason' => mb_substr($code, 0, 80)])];
        if ($until !== null && in_array($state, [WaHealth::Limited, WaHealth::Blocked], true)) {
            $changes['wa_health_until'] = $until;
        }
        SettingsWriter::applyIf(['wa_health' => $state->value], $changes, bumpVersion: false);
    }

    private static function after(WaHealth $from, WaHealth $to, HealthWriter $by, string $code, ?string $note, ?Carbon $until): void
    {
        WaLog::warning('health.transition', ['from' => $from->value, 'to' => $to->value, 'writer' => $by->value, 'reason' => mb_substr($code, 0, 80)]);

        // موضع قرار واحد للتدقيق (E21): الناقل مطفأ = صف مباشر هنا، ومفعّل = المستمع AuditWhatsAppEvents
        if (!FeatureFlags::on('wa_event_bus')) {
            $write = fn () => AuditLog::safe(AuditAction::WaHealthChange, 'wa_channel', null, $to->value, [
                'from' => $from->value, 'to' => $to->value, 'source' => $by->value, 'reason_code' => mb_substr($code, 0, 80),
            ]);
            $by === HealthWriter::Manual ? $write() : AuditActor::as('meta', $by->value, $write);
        }
        self::emit($from->value, $to->value, $by->value, mb_substr($code, 0, 80));

        // الرفع اليدوي ينبّه من مساره باسم الفاعل؛ والتنبيه داخل النظام وFCM لا عبر واتساب (القناة قد تكون المعطّلة)
        if ($by !== HealthWriter::Manual) {
            self::alert($from, $to, $code, $note, $until);
        }
    }

    private static function alert(WaHealth $from, WaHealth $to, string $code, ?string $note, ?Carbon $until): void
    {
        $why = $note !== null && $note !== '' ? $note : $code;
        $till = $until !== null ? ' حتى '.self::date($until) : '';
        $message = match (true) {
            $to === WaHealth::Blocked => ['⛔ ميتا حجبت الإرسال على رقم الشركة', "{$why}{$till}. البوت والموظفون لا يرسلون. التفاصيل والرفع اليدوي في بطاقة «صحة القناة»."],
            $to === WaHealth::Limited => ['⚠️ قيد جزئي من ميتا — ردود العملاء تعمل', "{$why}{$till}."],
            $to === WaHealth::TokenInvalid => ['🔑 رمز ميتا غير صالح — جدّد System User token',
                WaHealth::TokenInvalid->blocksSending() ? 'الإرسال متوقف حتى يُحفظ رمز جديد في إعدادات الواتساب.' : 'ميتا سترفض الإرسال حتى يُحفظ رمز جديد في إعدادات الواتساب.'],
            $to === WaHealth::Available && $from === WaHealth::Limited => ['✅ رُفع القيد الجزئي من ميتا', 'فحص ميتا يؤكد أن الإرسال متاح.'],
            $to === WaHealth::Available && $from === WaHealth::TokenInvalid => ['✅ رمز ميتا يعمل الآن', 'فحص ميتا بعد تجديد الرمز يؤكد أن الإرسال متاح.'],
            default => null,
        };
        if ($message !== null) {
            app(OpsAlerts::class)->notifyAdmins('health:'.$to->value.':'.mb_substr($code, 0, 60), $message[0], mb_substr($message[1], 0, 400), self::URL);
        }
    }

    /** المكان الوحيد الذي يطلق ChannelHealthChanged (بعد الاعتماد، عبر الناقل) */
    private static function emit(string $from, string $to, string $source, ?string $reasonCode): void
    {
        EventBus::emit(new ChannelHealthChanged($from, $to, $source, $reasonCode));
    }

    private static function mergedMeta(WaBotSetting $s, array $meta): array
    {
        return array_merge((array) ($s->wa_health_meta ?? []), $meta);
    }

    private static function limitedNote(array $snapshot): ?string
    {
        foreach ((array) ($snapshot['entities'] ?? []) as $entity) {
            foreach ((array) ($entity['additional_info'] ?? []) as $info) {
                if (is_string($info) && $info !== '') {
                    return mb_substr($info, 0, 160);
                }
            }
        }

        return null;
    }

    /** @return array{0: string, 1: ?string} الرمز من أول كيان محجوب، والوصف كما شرحته ميتا */
    private static function blockedReason(array $snapshot): array
    {
        foreach ((array) ($snapshot['entities'] ?? []) as $entity) {
            if (($entity['can_send_message'] ?? null) !== 'BLOCKED') {
                continue;
            }
            foreach ((array) ($entity['errors'] ?? []) as $err) {
                $code = is_numeric($err['error_code'] ?? null) ? (int) $err['error_code'] : null;
                $text = is_string($err['error_description'] ?? null) ? mb_substr($err['error_description'], 0, 160) : null;

                return ['poll:'.($code ?? 'BLOCKED'), $text];
            }
        }

        return ['poll:BLOCKED', null];
    }

    public static function date(Carbon $at): string
    {
        return $at->copy()->timezone('Asia/Amman')->format('Y-m-d H:i');
    }
}
