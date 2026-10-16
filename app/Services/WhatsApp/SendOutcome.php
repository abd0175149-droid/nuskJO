<?php

namespace App\Services\WhatsApp;

use App\Support\FeatureFlags;

/**
 * نتيجة إرسال لا نعرفها (E28، العلم connection_resilience): مهلة أو انقطاع بعد بدء الإرسال، أو «غير مؤكد» من دفتر
 * الإرسال (E19). ربما خرجت الرسالة، فالموظف يرى «قد تكون أُرسلت» مميزاً عن الرفض الصريح، ولا يعيدها قبل أن يراجع.
 * تعذّر الاتصال قبل الإرسال (HTTP_CONNECT_FAILED) رفض صريح: الرسالة لم تخرج.
 */
final class SendOutcome
{
    public const MAYBE_SENT = 'قد تكون الرسالة أُرسلت — راجع المحادثة قبل الإعادة';

    /** HTTP_ERROR: المسار القديم بلا meta_error_v2 لا يفرّق المهلة عن تعذّر الاتصال، وصف الموظف يُحفظ «غير مؤكد» */
    private const UNKNOWN_CODES = ['OUTCOME_UNKNOWN', 'SEND_IN_PROGRESS', 'HTTP_TIMEOUT', 'HTTP_NO_RESPONSE', 'HTTP_ERROR'];

    public static function enabled(): bool
    {
        return rescue(fn () => FeatureFlags::on('connection_resilience'), false, false);
    }

    public static function unknown(array $res): bool
    {
        return empty($res['ok']) && (!empty($res['unknown']) || in_array($res['code'] ?? null, self::UNKNOWN_CODES, true));
    }

    /** جسم 202: رمز واحد ونص واحد ورقم المرجع إن وُجد، بلا نص استثناء ولا رابط */
    public static function body(array $res): array
    {
        $ref = preg_match('/رقم المرجع:\s*([A-Za-z0-9-]+)/u', (string) ($res['error'] ?? ''), $m) ? $m[1] : null;

        return ['ok' => false, 'code' => 'OUTCOME_UNKNOWN', 'unknown' => true,
            'error' => self::MAYBE_SENT.($ref ? " (رقم المرجع: {$ref})" : '')]
            + array_intersect_key($res, array_flip(['row_id', 'status', 'message_id']));
    }
}
