<?php

namespace App\Services\WhatsApp;

/**
 * قواعد نص الرد الجاهز (E17.7). كل المعالجة بدوال mb_* وpreg بالعلم /u (لا trim() بقائمة محارف).
 *  - المتغيرات {{…}} من قائمة مسموحة فقط، وتُملأ في الخادم (QuickReplyRenderer).
 *  - لا سعر ثابتاً: الأسعار تتغير والنص الجاهز يصل عملاء كثيرين؛ {{quoted_price}} من طلب التسعير بدلاً منه.
 *  - ذكر بيانات الدفع لا يُرفض، بل ينتظر اعتماد من يملك whatsapp.settings.
 *  - لا طلب لصور الجوازات أو الهويات عبر واتساب (سياسة رسائل الأعمال؛ AMENDMENTS E14).
 */
final class QuickReplyText
{
    /** المتغير => وصفه في صفحة الإدارة */
    public const VARIABLES = [
        'name' => 'اسم العميل المربوط',
        'client_code' => 'كود العميل',
        'staff_name' => 'اسم الموظف',
        'company_name' => 'اسم الشركة',
        'company_phone' => 'هاتف الشركة',
        'request_number' => 'رقم آخر طلب تسعير',
        'offer_title' => 'عنوان العرض في آخر طلب',
        'quoted_price' => 'السعر المعروض في آخر طلب مسعّر',
    ];

    private const CURRENCY = '(?:د\.?\s?أ|دينار|دنانير|JOD|JD|ريال|SAR|\$|USD|دولار|€|EUR|يورو)';

    /** أي {{…}} في نص (للحارس قبل الإرسال): حرفي أو عربي أو رقم أو شرطة سفلية */
    public const PLACEHOLDER = '/\{\{\s*[\p{L}\p{N}_]+\s*\}\}/u';

    /** @return list<string> أسماء المتغيرات كما كُتبت */
    public static function variables(string $text): array
    {
        preg_match_all('/\{\{\s*([^{}]*?)\s*\}\}/u', $text, $m);

        return array_values(array_unique($m[1]));
    }

    /** @return list<string> المتغيرات غير المسموحة */
    public static function unknownVariables(string $text): array
    {
        return array_values(array_diff(self::variables($text), array_keys(self::VARIABLES)));
    }

    public static function hasPrice(string $text): bool
    {
        $t = self::latinDigits($text);
        $c = self::CURRENCY;

        return (bool) preg_match('/\d[\d٬,.\s]*\s*'.$c.'/iu', $t) || (bool) preg_match('/'.$c.'\s*\d/iu', $t);
    }

    public static function mentionsPayment(string $text): bool
    {
        return (bool) preg_match('/(cliq|كليك|iban|آيبان|ايبان|JO\d{2}[A-Z0-9]{4}|رقم الحساب|حساب بنكي|تحويل إلى|حوالة)/iu', self::latinDigits($text));
    }

    /** يطلب من العميل إرسال صورة جواز أو هوية عبر واتساب */
    public static function asksForIdDocument(string $text): bool
    {
        // أفعال الإرسال وحدها: «أحضر صورة الجواز للمكتب» ليست طلباً عبر واتساب
        $verb = '(?:أرسل|ارسل|إرسال|ارسال|ابعث|إبعث|ابعت|بعت|صوّر|شارك|send|share|upload)';
        $doc = '(?:جواز|جوازات|الجواز|هوية|الهوية|الهويات|بطاقة الأحوال|البطاقة الشخصية|passport|id card|national id)';

        return (bool) preg_match('/'.$verb.'.{0,40}'.$doc.'|'.$doc.'.{0,40}'.$verb.'/iu', $text);
    }

    public static function hasUnfilled(string $text): bool
    {
        return (bool) preg_match(self::PLACEHOLDER, $text);
    }

    /** @return list<string> المتغيرات الباقية بلا قيمة في نص للإرسال */
    public static function unfilled(string $text): array
    {
        preg_match_all('/\{\{\s*([\p{L}\p{N}_]+)\s*\}\}/u', $text, $m);

        return array_values(array_unique($m[1]));
    }

    /** الأرقام العربية الهندية والفارسية إلى لاتينية قبل المطابقة */
    public static function latinDigits(string $text): string
    {
        return strtr($text, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);
    }
}
