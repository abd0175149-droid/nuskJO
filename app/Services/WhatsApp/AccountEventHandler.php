<?php

namespace App\Services\WhatsApp;

use App\Enums\AuditAction;
use App\Enums\WaHealth;
use App\Jobs\RefreshChannelHealth;
use App\Models\AuditLog;
use App\Models\WaAccountEvent;
use App\Models\WaBotSetting;
use App\Services\Ops\OpsAlerts;
use App\Services\Ops\OpsHealth;
use App\Support\AuditActor;
use App\Support\SecretRedactor;
use Illuminate\Support\Carbon;

/**
 * أحداث حساب ميتا (E22، مع العلم channel_health؛ بدونه WhatsAppInbox::handleAccountEvent القديم كما هو):
 * 1) التصفية: أحداث الحساب بمعرّف WABA (WebhookFence)، وأحداث الرقم برقمنا المعروض أو معرّفه. لغيرنا: ignored_foreign.
 * 2) البصمة: صف واحد في wa_account_events لكل حدث (مفتاح E20 نفسه: الحقل والحساب ووقت entry والقيمة مرتبة)،
 *    فإعادات ميتا وزر Test المكرر بلا تنبيه ولا فحص ولا انتقال.
 * 3) التصنيف (classify): حرج أو تحذير أو معلومة، والأثر. القفل المباشر لثلاث حالات قاطعة فقط: DISABLE،
 *    وRESTRICTED_CUSTOMER_INITIATED_MESSAGING، وACCOUNT_DELETED. REINSTATE يقترح الرفع ولا يرفع، وقيود رسائل الشركة LIMITED.
 * 4) بعد كل حدث ذي معنى فحص health_status خلال ثوانٍ (RefreshChannelHealth)، والفحص مرجع الحالة.
 * المصدر المجهول (لم نعرف حسابنا أو رقمنا بعد) لا يقفل أبداً: تنبيه وفحص.
 */
final class AccountEventHandler
{
    /** الحقول المعالجة هنا. القوالب وتفضيلات العملاء تبقى في مسارها (لا تمس صحة القناة) */
    public const FIELDS = ['account_update', 'account_alerts', 'security', 'business_capability_update',
        'phone_number_quality_update', 'phone_number_name_update', 'account_review_update'];

    /** مستوى الحساب: entry.id معرّف WABA. وما سواها مستوى الرقم */
    private const ACCOUNT_LEVEL = ['account_update', 'account_alerts', 'business_capability_update', 'account_review_update'];

    /** القيد الذي يمس ردود خدمة العملاء: حجب. وما سواه (رسائل الشركة والمكالمات وإضافة رقم) قيد جزئي */
    private const BLOCKING_RESTRICTIONS = ['RESTRICTED_CUSTOMER_INITIATED_MESSAGING'];

    public static function handles(string $field): bool
    {
        return in_array($field, self::FIELDS, true);
    }

    /** مفتاح E20 نفسه (الحقل والحساب ووقت entry والقيمة)، بقيمة مرتبة المفاتيح: إعادة ميتا تطابقه، وحدث مماثل بعد أيام لا */
    public static function fingerprint(string $field, string $entryId, int $entryTime, array $value): string
    {
        return hash('sha256', $field.'|'.$entryId.'|'.$entryTime.'|'.json_encode(self::canonical($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** المفاتيح مرتبة تعاودياً (القوائم بترتيبها)، وبلا timestamp الخارجي */
    public static function canonical(array $value, bool $top = true): array
    {
        if ($top) {
            unset($value['timestamp']);
        }
        if (!array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $k => $v) {
            if (is_array($v)) {
                $value[$k] = self::canonical($v, false);
            }
        }

        return $value;
    }

    public static function handle(string $field, array $value, string $entryId, int $entryTime, WaBotSetting $settings): void
    {
        $event = self::eventName($field, $value);
        $foreign = WebhookFence::otherAccountEvent($field, $value, $entryId, $settings) || WebhookFence::otherNumberEvent($field, $value, $settings);
        $rule = $foreign ? ['severity' => 'info', 'effect' => 'ignored_foreign'] : self::classify($field, $value, $event);
        if (!$foreign && !self::sourceKnown($field, $value, $settings)) {
            $rule = self::unverified($rule, $field, $event);
        }

        $fingerprint = self::fingerprint($field, $entryId, $entryTime, $value);
        $inserted = WaAccountEvent::insertOrIgnore([
            'field' => mb_substr($field, 0, 48),
            'event' => mb_substr($event, 0, 64),
            'entry_id' => $entryId !== '' ? mb_substr($entryId, 0, 32) : null,
            'phone_number_id' => self::phoneNumberIdOf($field, $value),
            'severity' => $rule['severity'],
            'effect' => $rule['effect'],
            'fingerprint' => $fingerprint,
            'occurred_at' => self::occurredAt($value, $entryTime),
            // بيانات جهة أخرى (حساب أو رقم غريب، أو عيّنة زر Test) لا تُخزَّن
            'payload' => $foreign ? null : json_encode(self::payload($value), JSON_UNESCAPED_UNICODE),
            'handled_at' => $foreign ? now() : null,
            'created_at' => now(),
        ]);
        if ($inserted === 0) {
            WaLog::info('account.event.duplicate', ['field' => $field, 'event' => $event]);

            return;
        }
        if ($foreign) {
            $store = OpsHealth::store();
            $key = 'wa:acct:foreign:'.now()->format('Ymd');
            $store->add($key, 0, now()->addDays(3));
            $store->increment($key);
            WaLog::warning('account.event.other_number', ['field' => $field, 'event' => $event, 'entry' => $entryId]);

            return;
        }

        WaLog::warning('account.event', ['field' => $field, 'event' => $event, 'severity' => $rule['severity'], 'effect' => $rule['effect']]);
        $row = WaAccountEvent::where('fingerprint', $fingerprint)->first();
        if ($row !== null) {
            self::apply($row, $rule, $field, $event);
        }
    }

    /**
     * أثر فشل (قاعدة مشغولة، أو تعذّر الطابور): handled_at يبقى فارغاً، ويعيده الفحص التالي (RefreshChannelHealth).
     * آخر 24 ساعة و20 صفاً على الأكثر، بقواعد التصنيف الحالية وحمولة الصف نفسه.
     */
    public static function retryUnhandled(): int
    {
        $done = 0;
        $settings = WaBotSetting::current();
        $rows = WaAccountEvent::whereNull('handled_at')->where('created_at', '>=', now()->subDay())->orderBy('id')->limit(20)->get();
        foreach ($rows as $row) {
            $value = (array) ($row->payload ?? []);
            if ($value === [] || !empty($value['_truncated'])) {
                WaAccountEvent::whereKey($row->id)->update(['handled_at' => now()]);

                continue;
            }
            $rule = self::classify($row->field, $value, (string) $row->event);
            if (!self::sourceKnown($row->field, $value, $settings)) {
                $rule = self::unverified($rule, $row->field, (string) $row->event);
            }
            // الفحص الجاري نفسه بعدها مرجع الحالة: لا فحص ثانٍ من هنا
            self::apply($row, ['poll' => false] + $rule, $row->field, (string) $row->event);
            $done++;
        }

        return $done;
    }

    /**
     * جدول التصنيف. الحقول تُقرأ بحذر (data_get) لأنها تتغير بين الإصدارات، والقيمة المجهولة تنبيه وفحص لا قفل.
     *
     * @return array{severity: string, effect: string, code?: string, note?: ?string, until?: ?Carbon, alert?: array{0: string, 1: string}|null, poll?: bool, audit?: bool, meta?: array}
     */
    public static function classify(string $field, array $value, string $event): array
    {
        $none = ['severity' => 'info', 'effect' => 'none'];
        $alertPoll = fn (string $severity, string $code, string $title, string $body) => ['severity' => $severity, 'effect' => 'poll', 'code' => $code,
            'alert' => [$title, $body], 'poll' => true];

        if ($field === 'account_update') {
            switch ($event) {
                case 'DISABLED_UPDATE':
                    $state = strtoupper((string) data_get($value, 'ban_info.waba_ban_state', ''));
                    $date = self::text(data_get($value, 'ban_info.waba_ban_date'), 20);

                    return match ($state) {
                        'DISABLE' => ['severity' => 'critical', 'effect' => 'blocked', 'code' => 'ban:DISABLE', 'note' => 'ميتا عطّلت حساب واتساب للأعمال', 'poll' => true],
                        'SCHEDULE_FOR_DISABLE' => $alertPoll('critical', 'ban:SCHEDULE_FOR_DISABLE', '⛔ ميتا جدولت تعطيل حساب الواتساب',
                            'موعد التعطيل: '.($date ?? 'غير محدد').'. راجع بريد ميتا وBusiness Support Home قبل الموعد.'),
                        'REINSTATE' => ['severity' => 'info', 'effect' => 'unblock_suggested', 'code' => 'ban:REINSTATE', 'note' => 'ميتا أعادت الحساب (REINSTATE)', 'poll' => true],
                        default => $alertPoll('warn', 'ban:'.($state !== '' ? $state : 'UNKNOWN'), '⚠️ تحديث حظر من ميتا على الحساب', 'حالة غير معروفة؛ يُفحص الحساب الآن من ميتا.'),
                    };
                case 'ACCOUNT_RESTRICTION':
                    $items = array_values(array_filter((array) data_get($value, 'restriction_info', []), 'is_array'));
                    $types = array_values(array_unique(array_filter(array_map(fn ($i) => strtoupper(self::text($i['restriction_type'] ?? null, 64) ?? ''), $items))));
                    if ($types === []) {
                        return $alertPoll('warn', 'restriction:NONE', '⚠️ تحذير قيد من ميتا على الحساب', 'وصل حدث قيد بلا تفاصيل؛ يُفحص الحساب الآن من ميتا.');
                    }
                    $blocking = array_values(array_intersect($types, self::BLOCKING_RESTRICTIONS));
                    $until = self::latestExpiration($items, $blocking !== [] ? self::BLOCKING_RESTRICTIONS : null);

                    return $blocking !== []
                        ? ['severity' => 'critical', 'effect' => 'blocked', 'code' => 'restriction:'.$blocking[0], 'note' => 'ميتا قيّدت الرد على العملاء', 'until' => $until,
                            'poll' => true, 'meta' => ['restriction_types' => $types]]
                        : ['severity' => 'warn', 'effect' => 'limited', 'code' => 'restriction:'.$types[0], 'note' => 'قيد على رسائل الشركة أو المكالمات لا يمس الردود', 'until' => $until,
                            'poll' => true, 'meta' => ['restriction_types' => $types]];
                case 'ACCOUNT_VIOLATION':
                    $type = self::text(data_get($value, 'violation_info.violation_type'), 64);

                    return $alertPoll('critical', 'violation:'.($type ?? 'UNKNOWN'), '⚠️ مخالفة سياسات على حساب الواتساب',
                        'نوع المخالفة: '.($type ?? 'غير محدد').'. لا قفل؛ الحالة من فحص ميتا في بطاقة «صحة القناة».') + ['meta' => ['violation_type' => $type]];
                case 'ACCOUNT_DELETED':
                    return ['severity' => 'critical', 'effect' => 'blocked', 'code' => 'account:DELETED', 'note' => 'ميتا حذفت حساب واتساب للأعمال', 'poll' => true];
                case 'ACCOUNT_OFFBOARDED':
                    return $alertPoll('critical', 'account:OFFBOARDED', '⚠️ فُصل حساب واتساب للأعمال', 'وصل حدث ACCOUNT_OFFBOARDED من ميتا. راجع ربط الحساب قبل أن يتوقف الإرسال.');
                case 'ACCOUNT_RECONNECTED':
                    return $alertPoll('warn', 'account:RECONNECTED', '⚠️ أُعيد ربط حساب واتساب للأعمال', 'وصل حدث ACCOUNT_RECONNECTED من ميتا؛ يُفحص الحساب الآن.');
                default:
                    return $none;
            }
        }

        if ($field === 'account_alerts') {
            $severity = match (strtoupper((string) data_get($value, 'alert_severity'))) {
                'CRITICAL' => 'critical',
                'WARNING' => 'warn',
                default => 'info',
            };

            return $severity === 'info' ? $none : ['severity' => $severity, 'effect' => 'alert', 'code' => 'alert:'.$event,
                'alert' => [$severity === 'critical' ? '⛔ تنبيه حرج من ميتا على الحساب' : '⚠️ تنبيه من ميتا على الحساب', "النوع: {$event}. راجع WhatsApp Manager."]];
        }

        if ($field === 'security') {
            return match ($event) {
                // مؤشر محتمل على محاولة استيلاء على الرقم. النص ثابت: لا requester ولا رقم
                'PIN_RESET_REQUEST' => ['severity' => 'critical', 'effect' => 'alert', 'code' => 'security:PIN_RESET_REQUEST', 'audit' => true,
                    'alert' => ['🔐 طلب إعادة ضبط PIN لرقم الشركة', 'إن لم يكن منكم فغيّروا PIN من WhatsApp Manager فوراً.']],
                'PIN_CHANGED' => ['severity' => 'warn', 'effect' => 'alert', 'code' => 'security:PIN_CHANGED',
                    'alert' => ['🔐 تغيّر PIN رقم الشركة', 'إن لم يكن التغيير منكم فراجعوا WhatsApp Manager فوراً.']],
                default => $none,
            };
        }

        if ($field === 'business_capability_update' || $field === 'phone_number_quality_update') {
            // الحد اليومي يخص الرسائل التي تبدؤها الشركة وحدها (current_limit للشكل القديم)
            $tier = self::text(data_get($value, 'max_daily_conversations_per_business') ?? data_get($value, 'current_limit'), 32);
            $meta = $tier !== null ? ['meta' => ['tier' => $tier]] : [];
            if ($field === 'phone_number_quality_update' && in_array($event, ['FLAGGED', 'DOWNGRADE'], true)) {
                return ['severity' => 'warn', 'effect' => 'poll', 'code' => 'quality:'.$event, 'poll' => true] + $meta;
            }

            return $none + $meta;
        }

        if ($field === 'phone_number_name_update' || $field === 'account_review_update') {
            $rejected = strtoupper((string) data_get($value, 'decision')) === 'REJECTED';
            $title = $field === 'phone_number_name_update' ? '⚠️ ميتا رفضت الاسم المعروض للرقم' : '⚠️ ميتا رفضت مراجعة الحساب';

            return $rejected ? ['severity' => 'warn', 'effect' => 'alert', 'code' => $field.':REJECTED', 'alert' => [$title, 'راجع التفاصيل في WhatsApp Manager.']] : $none;
        }

        return $none;
    }

    /** الأثر، ثم handled_at. استثناء الأثر يُسجَّل ويبقي الصف بلا handled_at لإعادته */
    private static function apply(WaAccountEvent $row, array $rule, string $field, string $event): void
    {
        try {
            $code = $rule['code'] ?? ($field.':'.$event);
            match ($rule['effect']) {
                'blocked' => ChannelHealth::fromWebhook(WaHealth::Blocked, $code, $rule['until'] ?? null, $rule['note'] ?? null, $rule['meta'] ?? []),
                'limited' => ChannelHealth::fromWebhook(WaHealth::Limited, $code, $rule['until'] ?? null, $rule['note'] ?? null, $rule['meta'] ?? []),
                'unblock_suggested' => ChannelHealth::suggestUnblock($rule['note'] ?? $code, $code),
                default => null,
            };
            if (!in_array($rule['effect'], ['blocked', 'limited'], true) && !empty($rule['meta'])) {
                ChannelHealth::remember($rule['meta']);
            }
            if (!empty($rule['alert'])) {
                app(OpsAlerts::class)->notifyAdmins('health:acct:'.$field.':'.mb_substr($code, 0, 60), $rule['alert'][0], $rule['alert'][1], ChannelHealth::URL);
            }
            if (!empty($rule['audit'])) {
                AuditActor::as('meta', $field, fn () => AuditLog::safe(AuditAction::WaAccountEventCritical, 'wa_account_event', $row->id, $event, [
                    'field' => $field, 'event' => $event, 'code' => $code,
                ]));
            }
            if (!empty($rule['poll'])) {
                RefreshChannelHealth::dispatch()->delay(now()->addSeconds((int) config('whatsapp.health.poll_delay_seconds', 15)));
            }
            WaAccountEvent::whereKey($row->id)->update(['handled_at' => now()]);
        } catch (\Throwable $e) {
            WaLog::error('account.event.effect_failed', ['field' => $field, 'event' => $event, 'event_id' => $row->id, 'type' => class_basename($e)]);
        }
    }

    /** لا نعرف حسابنا (أو رقمنا) بعد: لا قفل ولا اقتراح على حدث مجهول المصدر، بل تنبيه وفحص */
    private static function unverified(array $rule, string $field, string $event): array
    {
        if (!in_array($rule['effect'], ['blocked', 'limited', 'unblock_suggested'], true)) {
            return $rule;
        }

        return ['severity' => $rule['severity'], 'effect' => 'poll', 'code' => 'unverified:'.($rule['code'] ?? $event), 'poll' => true,
            'alert' => ['⚠️ حدث حساب من ميتا لم يُتحقق من مصدره', "الحدث: {$event} ({$field}). لم يُقفل الإرسال؛ يُفحص الآن من ميتا. اضبط WA_WABA_ID لتمييز أحداث حسابنا."]];
    }

    /** نعرف ما نقارن به: معرّف حسابنا لأحداث الحساب، ورقمنا المعروض أو معرّفه لأحداث الرقم */
    private static function sourceKnown(string $field, array $value, WaBotSetting $s): bool
    {
        if (in_array($field, self::ACCOUNT_LEVEL, true)) {
            if ($field === 'account_alerts' && strtoupper((string) data_get($value, 'entity_type')) === 'PHONE_NUMBER') {
                return (string) $s->phoneNumberId() !== '' && (string) data_get($value, 'entity_id') === (string) $s->phoneNumberId();
            }

            return (WebhookFence::account($s)['id'] ?? (string) $s->wa_waba_id) !== '';
        }
        $pnid = (string) data_get($value, 'metadata.phone_number_id', '');

        return (string) $s->wa_display_phone !== '' || ($pnid !== '' && $pnid === (string) $s->phoneNumberId());
    }

    private static function eventName(string $field, array $value): string
    {
        $event = data_get($value, 'event') ?: data_get($value, 'alert_type') ?: data_get($value, 'decision');
        if (!is_scalar($event) || (string) $event === '') {
            $event = $field === 'business_capability_update' ? 'CAPABILITY' : $field;
        }

        return mb_substr((string) preg_replace('/[^A-Za-z0-9_]/', '', strtoupper((string) $event)), 0, 64) ?: 'UNKNOWN';
    }

    private static function phoneNumberIdOf(string $field, array $value): ?string
    {
        $pnid = data_get($value, 'metadata.phone_number_id')
            ?? ($field === 'account_alerts' && strtoupper((string) data_get($value, 'entity_type')) === 'PHONE_NUMBER' ? data_get($value, 'entity_id') : null);

        return is_scalar($pnid) && (string) $pnid !== '' ? mb_substr((string) $pnid, 0, 64) : null;
    }

    private static function occurredAt(array $value, int $entryTime): ?Carbon
    {
        $ts = data_get($value, 'timestamp');
        $ts = is_numeric($ts) && (int) $ts > 0 ? (int) $ts : $entryTime;

        return $ts > 0 ? Carbon::createFromTimestamp($ts) : null;
    }

    /** القيمة بعد SecretRedactor، و16KB حداً أقصى (وإلا المفاتيح وحدها) */
    private static function payload(array $value): array
    {
        $clean = SecretRedactor::cleanArray($value);
        $bytes = strlen((string) json_encode($clean, JSON_UNESCAPED_UNICODE));

        return $bytes <= (int) config('whatsapp.health.event_payload_max_bytes', 16384)
            ? $clean
            : ['_truncated' => true, 'bytes' => $bytes, 'keys' => array_slice(array_keys($value), 0, 40)];
    }

    /** أبعد انتهاء بين عناصر القيد (أو عناصر الأنواع المذكورة وحدها) */
    private static function latestExpiration(array $items, ?array $types): ?Carbon
    {
        $latest = null;
        foreach ($items as $item) {
            if ($types !== null && !in_array(strtoupper((string) ($item['restriction_type'] ?? '')), $types, true)) {
                continue;
            }
            $exp = $item['expiration'] ?? null;
            if (is_numeric($exp) && (int) $exp > 0) {
                $latest = max($latest ?? 0, (int) $exp);
            }
        }

        return $latest !== null ? Carbon::createFromTimestamp($latest) : null;
    }

    private static function text(mixed $v, int $max): ?string
    {
        return is_scalar($v) && (string) $v !== '' ? mb_substr((string) $v, 0, $max) : null;
    }
}
