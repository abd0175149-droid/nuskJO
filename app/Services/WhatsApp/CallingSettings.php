<?php

namespace App\Services\WhatsApp;

use App\Models\WaAccountEvent;
use App\Models\WaBotSetting;
use App\Models\WaCall;
use App\Services\WhatsApp\Settings\SettingsWriter;
use App\Support\SecretRedactor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * إعدادات الاتصال عند ميتا وبطاقة «📞 المكالمات» (E39). آخر قراءة في wa_bot_settings.calling_snapshot: من زر «قراءة من ميتا»
 * أو الأمر whatsapp:calling-settings، أو من ويبهوك account_settings_update (ميتا ترسله لكل تغيير، ومنه تغييرنا نحن)،
 * فيظهر في البطاقة أي تغيير جرى خارج النظام. الكتابة عند ميتا من الأمر المؤكد وحده، لا من الواجهة.
 */
final class CallingSettings
{
    /** الحقول المحفوظة من القراءة (لا SIP ولا أي سر) */
    public static function sanitize(array $calling): array
    {
        $out = array_intersect_key($calling, array_flip(WhatsAppChannel::CALLING_FIELDS));
        if (isset($out['call_hours']) && !is_array($out['call_hours'])) {
            unset($out['call_hours']);
        }

        return $out;
    }

    /** آخر قراءة: بلا رفع النسخة وبلا تدقيق (معرفة تشغيلية كفحوص ميتا) */
    public static function remember(array $calling, string $source): void
    {
        if (!Schema::hasColumn('wa_bot_settings', 'calling_snapshot')) {
            return;
        }
        SettingsWriter::apply(['calling_snapshot' => ['calling' => self::sanitize($calling), 'source' => $source, 'at' => now()->toIso8601String()]],
            null, null, bumpVersion: false, audit: 'none');
    }

    /**
     * ويبهوك account_settings_update (العلم calls_webhook؛ بدونه حقل مجهول كما في E20): لرقمنا وحده، صف معلوماتي في
     * wa_account_events، وتحديث آخر قراءة إن حمل إعدادات الاتصال.
     */
    public static function fromWebhook(array $value, string $entryId, int $entryTime, string $ownId): void
    {
        $pnid = (string) (data_get($value, 'phone_number_id') ?? data_get($value, 'metadata.phone_number_id') ?? '');
        if ($pnid !== '' && $ownId !== '' && $pnid !== $ownId) {
            WaLog::info('calls.settings_foreign', ['field' => 'account_settings_update']);

            return;
        }
        $calling = data_get($value, 'calling') ?? data_get($value, 'settings.calling');
        $event = mb_substr((string) preg_replace('/[^A-Z0-9_]/', '', strtoupper((string) (data_get($value, 'type') ?: data_get($value, 'event') ?: 'SETTINGS_UPDATE'))), 0, 64) ?: 'SETTINGS_UPDATE';

        if (Schema::hasTable('wa_account_events')) {
            WaAccountEvent::insertOrIgnore([
                'field' => 'account_settings_update',
                'event' => $event,
                'entry_id' => $entryId !== '' ? mb_substr($entryId, 0, 32) : null,
                'phone_number_id' => $pnid !== '' ? mb_substr($pnid, 0, 64) : null,
                'severity' => 'info',
                'effect' => 'none',
                'fingerprint' => AccountEventHandler::fingerprint('account_settings_update', $entryId, $entryTime, $value),
                'occurred_at' => $entryTime > 0 ? Carbon::createFromTimestamp($entryTime) : now(),
                'payload' => json_encode(is_array($calling) ? ['calling' => self::sanitize($calling)] : ['keys' => array_slice(array_keys(SecretRedactor::cleanArray($value)), 0, 20)], JSON_UNESCAPED_UNICODE),
                'handled_at' => now(),
                'created_at' => now(),
            ]);
        }
        if (is_array($calling)) {
            self::remember($calling, 'webhook');
        }
        WaLog::info('calls.settings_update', ['event' => $event]);
    }

    /** بطاقة الإعدادات: آخر قراءة وطريقة الرد ونص المتابعة والأعداد والتنبيه الأحمر. بلا رقم ولا سر */
    public static function panel(): array
    {
        $s = WaBotSetting::current();
        $snapshot = (array) ($s->calling_snapshot ?? []);
        $calling = (array) ($snapshot['calling'] ?? []);
        $mode = $s->calls_answer_mode ?: 'none';
        $templates = CallFollowup::templates($s);
        $today = Carbon::now('Asia/Amman')->startOfDay()->setTimezone(config('app.timezone'));
        $missed = fn (Carbon $since) => CallEventHandler::available() ? WaCall::where('missed', true)->where('created_at', '>=', $since)->count() : 0;

        return [
            'enabled' => CallEventHandler::enabled(),
            'followup_enabled' => CallFollowup::enabled(),
            'snapshot' => $snapshot === [] ? null : ['calling' => $calling, 'source' => $snapshot['source'] ?? null, 'at' => $snapshot['at'] ?? null],
            'answer_mode' => $mode,
            'answer_modes' => CallEventHandler::ANSWER_MODES,
            'auto_reject' => (bool) $s->calls_auto_reject,
            // تفعيل الاتصال عند ميتا بلا طريقة رد: كل مكالمة ستفوت
            'warning' => strtoupper((string) ($calling['status'] ?? '')) === 'ENABLED' && $mode === 'none',
            'followup' => [
                'text' => $s->calls_followup_text,
                'text_en' => $s->calls_followup_text_en,
                'default' => CallFollowup::DEFAULT_TEXT,
                'effective' => $templates['ar'],
                'variables' => RuleTextGuard::VARIABLE_LABELS,
                'hours' => (int) config('whatsapp.calls.followup_hours', 6),
            ],
            'missed' => ['today' => $missed($today), 'week' => $missed($today->copy()->subDays(6))],
            'rejected' => CallEventHandler::rejectedCounts(),
        ];
    }
}
