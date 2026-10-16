<?php

namespace App\Services\WhatsApp;

use App\Services\Ops\OpsAlerts;
use App\Services\Ops\OpsHealth;

/**
 * حقول الويبهوك خارج المعالجة (E20): تُسجَّل باسمها وعددها فقط، بلا أي جزء من value.
 * calls: نوع الحدث وحده. التعايش (smb_message_echoes وhistory وsmb_app_state_sync): تنبيه فوري، ولا يصل للبوت.
 */
final class WebhookFields
{
    /** ما يعالجه handleWebhook: الرسائل، وأحداث الحساب (E04/E09)، وتغيّر المعرّف (E11) */
    public const KNOWN = [
        'messages', 'account_update', 'phone_number_quality_update', 'account_alerts', 'security',
        'business_capability_update', 'account_review_update', 'phone_number_name_update', 'user_preferences',
        'message_template_status_update', 'message_template_quality_update', 'message_template_components_update',
        'template_category_update', 'user_id_update',
    ];

    /** تعني أن أحداً فعّل التعايش (تطبيق الأعمال على الهاتف) على رقمنا */
    public const COEXISTENCE = ['smb_message_echoes', 'history', 'smb_app_state_sync'];

    public static function name(mixed $field): string
    {
        $name = substr((string) preg_replace('/[^a-z0-9_]/', '', strtolower(is_scalar($field) ? (string) $field : '')), 0, 40);

        return $name !== '' ? $name : '-';
    }

    public static function isKnown(string $field): bool
    {
        return in_array($field, self::KNOWN, true);
    }

    /** حقل لا نعالجه: سطر وعدّاد يومي وإشعار واحد في اليوم لكل حقل */
    public static function unknown(string $field): void
    {
        $count = self::bump($field);
        WaLog::warning('webhook.unknown_field', ['field' => $field, 'count' => $count]);

        if (OpsHealth::store()->add("wa:field:alerted:{$field}:".now()->format('Ymd'), 1, now()->addDays(2))) {
            app(OpsAlerts::class)->notifyAdmins('wa:field:'.$field, '⚠️ حقل ويبهوك غير معالج',
                "وصل حقل ويبهوك غير معالج: {$field} — راجع الاشتراكات في App Dashboard.");
        }
    }

    /** المكالمات (لا نشترك فيها): نوع الحدث وبصمة مقتطعة لمعرّف المكالمة، بلا SDP ولا أرقام. لا تخزين ولا رد */
    public static function calls(array $value): void
    {
        $count = self::bump('calls');
        $events = [];
        foreach ((array) data_get($value, 'calls', []) as $call) {
            $events[] = ['event' => self::name(data_get($call, 'event')), 'call' => substr(sha1((string) data_get($call, 'id', '')), 0, 10)];
        }
        foreach ((array) data_get($value, 'statuses', []) as $st) {
            $events[] = ['status' => self::name(data_get($st, 'status')), 'call' => substr(sha1((string) data_get($st, 'id', '')), 0, 10)];
        }
        WaLog::warning('webhook.calls', ['count' => $count, 'events' => array_slice($events, 0, 10)]);
    }

    /** أحداث تعايش على رقم الإنتاج: البوت قد يتعارض مع ردود الهاتف. لا تُمرَّر للبوت أبداً */
    public static function coexistence(string $field): void
    {
        $count = self::bump($field);
        WaLog::warning('webhook.coexistence', ['field' => $field, 'count' => $count]);
        app(OpsAlerts::class)->notifyAdmins('wa:coexistence', '⚠️ وصلت أحداث تعايش على رقم الإنتاج',
            'البوت قد يتعارض مع ردود الهاتف. راجع ربط الرقم في WhatsApp Manager قبل أي رد آلي.');
    }

    /** @return array<string, int> الحقول غير المعالجة اليوم وأمس بأعدادها */
    public static function counts(): array
    {
        $store = OpsHealth::store();
        $out = [];
        foreach ([now()->format('Ymd'), now()->subDay()->format('Ymd')] as $d) {
            foreach ((array) $store->get("wa:fields:names:{$d}", []) as $field) {
                $out[$field] = ($out[$field] ?? 0) + (int) $store->get("wa:field:{$field}:{$d}", 0);
            }
        }
        arsort($out);

        return $out;
    }

    private static function bump(string $field): int
    {
        $store = OpsHealth::store();
        $day = now()->format('Ymd');
        $key = "wa:field:{$field}:{$day}";
        $store->add($key, 0, now()->addDays(3));
        $count = (int) $store->increment($key);

        $names = (array) $store->get("wa:fields:names:{$day}", []);
        if (!in_array($field, $names, true) && count($names) < 20) {
            $names[] = $field;
            $store->put("wa:fields:names:{$day}", $names, now()->addDays(3));
        }

        return $count;
    }
}
