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
        'http', 'host', 'entry', 'reason', 'offer_id', 'code', 'bytes', 'policy_reason',
        // E18: تصنيف أخطاء ميتا (لا body ولا phone ولا name ولا token ولا Authorization ولا رابط كامل)
        'error_code', 'category', 'attempt', 'scope',
        // E20: استقبال الويبهوك (أنواع أحداث المكالمات ببصمة مقتطعة، ورقم الصف والعنصر، وأعداد المحو والإعادة)
        'events', 'item', 'event_id', 'wiped', 'deleted', 'overdue', 'processed', 'failed', 'dead',
        // E21: اسم صنف المستمع الذي فشل (fail-open)
        'listener',
        // E30: البحث والتصدير (صف التدقيق ومحرّك البحث، بلا نص الاستعلام)
        'audit_id', 'engine',
        // E37: رقم قاعدة الرد الحتمية (بلا نص العميل)
        'rule',
        // E32: الملفات الصادرة (النوع المكتشف، وأول 12 حرفاً من البصمة؛ لا اسم ملف أبداً)
        'mime', 'sha12',
        // E38: رقم صف الرمز وقناة الدخول (لا الرمز ولا الرابط ولا الرسالة المعبأة)
        'qr_link_id', 'channel',
        // E22: انتقالات صحة القناة (الحالتان والكاتب ورمز السبب) وعدّاد الفشل المتتالي
        'from', 'to', 'writer', 'effect', 'severity',
        // E39: بصمة مقتطعة لمعرّف المكالمة (لا رقم المتصل ولا SDP)، ووضع التعايش، ورقم دفعة السجل السابق
        'call', 'mode', 'chunk',
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
