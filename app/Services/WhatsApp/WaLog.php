<?php

namespace App\Services\WhatsApp;

use Illuminate\Support\Facades\Log;

/**
 * سجل الواتساب (قناة whatsapp: JSON يومي + سجل التطبيق). سياسة البيانات الشخصية بالبناء:
 * مفاتيح السياق من قائمة مسموحة فقط، وما سواها يُسقط. لا نص رسالة، ولا اسم، ولا رقم عميل (آخر 4 أرقام فقط).
 */
final class WaLog
{
    private const ALLOWED = [
        'conv_id', 'message_id', 'wamid', 'status', 'meta_code', 'fbtrace_id', 'source', 'type', 'ms', 'model',
        'tokens_in', 'tokens_out', 'tool', 'field', 'event', 'count', 'phone_tail', 'exception',
        'http', 'host', 'entry', 'reason', 'offer_id', 'code', 'bytes',
    ];

    public static function info(string $event, array $context = []): void
    {
        self::write('info', $event, $context);
    }

    public static function notice(string $event, array $context = []): void
    {
        self::write('notice', $event, $context);
    }

    public static function warning(string $event, array $context = []): void
    {
        self::write('warning', $event, $context);
    }

    public static function error(string $event, array $context = []): void
    {
        self::write('error', $event, $context);
    }

    /** @return array<string, mixed> */
    public static function filter(array $context): array
    {
        $context = array_intersect_key($context, array_flip(self::ALLOWED));
        if (isset($context['phone_tail'])) {
            $context['phone_tail'] = substr(preg_replace('/\D/', '', (string) $context['phone_tail']), -4);
        }

        return $context;
    }

    private static function write(string $level, string $event, array $context): void
    {
        try {
            Log::channel('whatsapp')->{$level}($event, self::filter($context));
        } catch (\Throwable) {
            // السجل لا يُسقط معالجة الرسالة أبداً
        }
    }
}
