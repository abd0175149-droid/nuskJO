<?php

namespace App\Services\WhatsApp;

/**
 * حارس التحويل لموظف — قواعد حتمية لا يقرّرها النموذج.
 *
 * لماذا وُجد: في 2026-09-21 كتب عميل «حولني» فردّ النموذج «تم تحويل محادثتك»
 * دون أن يستدعي أداة التحويل. لم يُشعَر أحد وبقي البوت شغّالاً، والعميل ينتظر.
 * فالتحويل صار مضموناً بالكود من طرفيه:
 *   ① قبل النموذج: طلبٌ صريح لموظف يُحوَّل مباشرةً بلا سؤال.
 *   ② بعد النموذج: ردٌّ يزعم التحويل دون أداة تحويل يُنفَّذ تحويله فعلاً.
 */
class HandoffGuard
{
    /** أدوات تحوّل المحادثة فعلاً — إن استُدعيت إحداها فالزعم صادق */
    public const ESCALATING_TOOLS = ['handoff_to_human', 'request_quote', 'confirm_booking'];

    /** طلب صريح للتحدّث مع إنسان */
    private const WANTS_HUMAN = [
        // الأنماط مكتوبة بعد التوحيد (normalize): ا بدل أ/إ، ه بدل ة، و بدل ؤ، ي بدل ى
        '/(^|\s)حولو?ني(\s|$)/u',
        '/(^|\s)حوليني(\s|$)/u',
        '/(بدي|بدنا|اريد|ابغي|بغيت|ممكن)\s+(اتواصل|احكي|اتكلم|اتحدث|التحدث|التواصل|الحكي)\s+(مع|ل)\s*(موظف|حدا|حد|شخص|انسان|بني ?ادم|مسوول|الاداره|المدير)/u',
        '/(بدي|بدنا|اريد|ابغي|بغيت)\s+(موظف|انسان|شخص حقيقي|بني ?ادم|مسوول)/u',
        '/(خلي|خلو|بدي)\s+(حدا|موظف|شخص)\s+(يحكي|يكلم|يتواصل|يرد)/u',
        '/(كلموني|اتصلو|اتصلوا)\s+(فيي|علي|بي|عليي)/u',
        '/(ما بدي|مش بدي|لا اريد)\s+(احكي مع\s+)?(بوت|روبوت|اله)/u',
        '/\b(talk|speak|chat)\s+(to|with)\s+(a|an|someone|somebody|the|your|real|human|agent|person)\b/i',
        '/\b(real person|live agent|human agent|representative)\b/i',
    ];

    /** ردٌّ يزعم أنّ المحادثة حُوّلت أو أنّ موظفاً سيتواصل */
    private const CLAIMS_HANDOFF = [
        '/تم\s+تحويل/u',
        '/حولت\s+(محادثتك|طلبك|استفسارك|رسالتك|المحادثه)/u',
        '/حولتك/u',
        '/سيتواصل\s+معك\s+(احد|موظف|زميل|فريق)/u',
        '/سيتابع\s+معك\s+(احد|موظف|زميل|فريق)/u',
        '/رح\s+(يتواصل|يحكي|يتابع)\s+معك\s+(حدا|موظف|زميل)/u',
        '/\b(transferred|passed (you|your \w+) (on|to)|a colleague will|a team member will|someone will (contact|get back))\b/i',
    ];

    public static function wantsHuman(string $text): bool
    {
        return self::matchesAny(self::normalize($text), self::WANTS_HUMAN);
    }

    public static function claimsHandoff(string $text): bool
    {
        return self::matchesAny(self::normalize($text), self::CLAIMS_HANDOFF);
    }

    /** هل كتب العميل بحروف لاتينية أساساً؟ لاختيار لغة الرسائل التي يبنيها الكود */
    public static function isLatin(string $text): bool
    {
        $latin = preg_match_all('/[A-Za-z]/u', $text);
        $arabic = preg_match_all('/\p{Arabic}/u', $text);

        return $latin > 0 && $latin > $arabic;
    }

    /** رسالة تأكيد التحويل — يبنيها الكود لا النموذج */
    public static function confirmation(bool $latin): string
    {
        return $latin
            ? "Sure. I've passed your conversation to a colleague, who will get back to you as soon as possible."
            : 'أبشر، حوّلت محادثتك لأحد زملائنا وسيتواصل معك بأقرب وقت إن شاء الله.';
    }

    /** توحيد الكتابة العربية: حذف التشكيل والتطويل، وتوحيد الألف والياء والتاء المربوطة */
    public static function normalize(string $text): string
    {
        $t = mb_strtolower($text);
        $t = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $t) ?? $t;
        $t = strtr($t, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ى' => 'ي', 'ة' => 'ه', 'ؤ' => 'و', 'ئ' => 'ي']);
        $t = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $t) ?? $t;

        return trim(preg_replace('/\s+/u', ' ', $t) ?? $t);
    }

    private static function matchesAny(string $text, array $patterns): bool
    {
        foreach ($patterns as $p) {
            if (preg_match($p, $text)) {
                return true;
            }
        }

        return false;
    }
}
