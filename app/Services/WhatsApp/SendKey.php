<?php

namespace App\Services\WhatsApp;

use App\Models\QuoteRequest;
use App\Models\WaConversation;
use App\Models\WaMessage;

/**
 * مفاتيح منع التكرار لدفتر الإرسال (E19): حتمية لكل مسار آلي، فإعادة الوظيفة أو المُصالِح أو نقرتان لا تُخرج رسالتين.
 * المفتاح يحمل نوع الإرسال (نص، بطاقة عرض، تحويل…) فلا يمنع رداً مشروعاً آخر للوارد نفسه.
 * لا هاتف ولا اسم ولا نص في أي مفتاح، ولا يتجاوز 64 حرفاً (الطويل يُختصر بـ sha1 بعد البادئة).
 */
final class SendKey
{
    public const MAX = 64;

    /** نافذة منع تكرار السعر نفسه (ثوانٍ) */
    public const QUOTE_BUCKET_SECONDS = 600;

    /** أعلى وارد يغطيه الرد: من تشغيل الرد الحالي، وإلا أعلى وارد في المحادثة الآن */
    public static function upto(WaConversation $conv): int
    {
        $upto = ReplyRun::current()?->uptoId;
        if ($upto) {
            return (int) $upto;
        }

        return (int) WaMessage::where('conversation_id', $conv->id)->where('direction', 'in')->max('id');
    }

    /** رد البوت: text | card:{offer} | handoff | failsafe | outage */
    public static function reply(WaConversation $conv, ?int $upto, string $kind): string
    {
        return self::make('reply', $conv->id, $upto ?? self::upto($conv), $kind);
    }

    /** المساعد الإداري: text | fail | outage | preview */
    public static function admin(WaConversation $conv, ?int $upto, string $kind): string
    {
        return self::make('admin', $conv->id, $upto ?? self::upto($conv), $kind);
    }

    /** تأكيد إيقاف الرسائل التسويقية لكلمة الإيقاف الواردة */
    public static function optout(string $wamid): string
    {
        return self::make('optout', $wamid);
    }

    /** رسالة التحويل عند تعطل مزوّد الذكاء (E12) */
    public static function outage(WaConversation $conv, ?int $upto): string
    {
        return self::make('outage', $conv->id, $upto ?? self::upto($conv));
    }

    /** زر طلب الرقم بمصدر النظام (E11) */
    public static function reqcontact(WaConversation $conv, ?int $upto): string
    {
        return self::make('reqcontact', $conv->id, $upto ?? self::upto($conv));
    }

    /** موافقة العميل على حفظ ملفاته (E14) وردّ الرفض */
    public static function consent(WaConversation $conv, ?int $upto, string $kind): string
    {
        return self::make('consent', $conv->id, $upto ?? self::upto($conv), $kind);
    }

    /**
     * سعر طلب التسعير: السعر والملاحظة نفسيهما خلال 10 دقائق لا يُرسلان مرتين. $nonce لـ «أعد الإرسال» الصريح.
     */
    public static function quote(QuoteRequest|int $qr, string|float|int|null $price, ?string $note, ?string $nonce = null, ?int $now = null): string
    {
        $id = $qr instanceof QuoteRequest ? $qr->id : $qr;
        $hash = substr(hash('sha256', trim((string) $price).'|'.trim((string) $note)), 0, 16);
        $bucket = intdiv($now ?? time(), self::QUOTE_BUCKET_SECONDS);

        return self::make('quote', $id, $hash, $bucket, ...($nonce !== null ? [$nonce] : []));
    }

    /** رسالة الموظف: Idempotency-Key من المنشئ (UUID) */
    public static function staff(string $uuid): ?string
    {
        $uuid = strtolower(trim($uuid));

        return self::isUuid($uuid) ? 'staff:'.$uuid : null;
    }

    public static function isUuid(?string $value): bool
    {
        return is_string($value) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1;
    }

    private static function make(string $prefix, string|int ...$parts): string
    {
        $key = $prefix.':'.implode(':', array_map(fn ($p) => preg_replace('/[^A-Za-z0-9._\-]/', '', (string) $p), $parts));

        return strlen($key) <= self::MAX ? $key : $prefix.':'.substr(sha1(substr($key, strlen($prefix) + 1)), 0, 40);
    }
}
