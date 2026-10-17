<?php

namespace App\Services\WhatsApp;

/**
 * حارس الأرقام في ترجمة رد الموظف (E29): أرقام النص العربي الأصلي وأرقام الترجمة النهائية (بعد تعديل الموظف) يجب أن
 * تكون المجموعة نفسها (multiset)، ولا رقم مكتوباً بالحروف في الترجمة، فلا يغيّر النموذج سعراً أو تاريخاً بصمت.
 * تكافئه resources/js/utils/numberGuard.js في المتصفح للعرض الفوري، والخادم هو الحَكَم (NumberGuardTest يقارن القائمتين).
 * قيد معروف: تبديل ترتيب يوم وشهر لا يُكشف بالمجموعة؛ يعالجه الموجّه وقراءة الموظف.
 */
final class NumberGuard
{
    private const DIGITS = ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9'];

    /**
     * كلمات الأرقام لكل لغة (بحدود حرف قبلها وبعدها). مستبعد عمداً ما هو أداة تنكير أو كلمة شائعة بمعنى آخر في لغته
     * (un/une/uno/una، bir، یک ونه الفارسيتان، ایک ودو الأورديتان، एक ودो، এক وনয়)، وإلا مُنع إرسال كل جملة تقريباً.
     * نسخة طبق الأصل بين علامتي words في numberGuard.js.
     */
    public const WORDS = [
        'en' => ['one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen',
            'sixteen', 'seventeen', 'eighteen', 'nineteen', 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety',
            'hundred', 'thousand', 'million', 'billion'],
        'ar' => ['واحد', 'واحدة', 'اثنان', 'اثنين', 'اثنتان', 'اثنتين', 'ثلاث', 'ثلاثة', 'أربع', 'أربعة', 'خمس', 'خمسة', 'ست', 'ستة', 'سبع', 'سبعة',
            'ثمان', 'ثماني', 'ثمانية', 'تسع', 'تسعة', 'عشر', 'عشرة', 'عشرون', 'عشرين', 'ثلاثون', 'ثلاثين', 'أربعون', 'أربعين', 'خمسون', 'خمسين',
            'ستون', 'ستين', 'سبعون', 'سبعين', 'ثمانون', 'ثمانين', 'تسعون', 'تسعين', 'مئة', 'مائة', 'مئتان', 'مئتين', 'مائتان', 'مائتين',
            'ألف', 'الف', 'ألفان', 'ألفين', 'آلاف', 'مليون', 'ملايين'],
        'ur' => ['تین', 'چار', 'پانچ', 'چھ', 'سات', 'آٹھ', 'نو', 'دس', 'گیارہ', 'بارہ', 'بیس', 'سو', 'ہزار', 'لاکھ', 'کروڑ'],
        'id' => ['satu', 'dua', 'tiga', 'empat', 'lima', 'enam', 'tujuh', 'delapan', 'sembilan', 'sepuluh', 'sebelas', 'belas', 'puluh',
            'seratus', 'ratus', 'seribu', 'ribu', 'juta'],
        'ms' => ['satu', 'dua', 'tiga', 'empat', 'lima', 'enam', 'tujuh', 'lapan', 'sembilan', 'sepuluh', 'sebelas', 'belas', 'puluh',
            'seratus', 'ratus', 'seribu', 'ribu', 'juta'],
        'tr' => ['iki', 'üç', 'dört', 'beş', 'altı', 'yedi', 'sekiz', 'dokuz', 'on', 'yirmi', 'otuz', 'kırk', 'elli', 'altmış', 'yetmiş',
            'seksen', 'doksan', 'yüz', 'bin', 'milyon'],
        'fr' => ['deux', 'trois', 'quatre', 'cinq', 'six', 'sept', 'huit', 'neuf', 'dix', 'onze', 'douze', 'treize', 'quatorze', 'quinze', 'seize',
            'vingt', 'trente', 'quarante', 'cinquante', 'soixante', 'cent', 'cents', 'mille', 'million', 'millions'],
        'es' => ['dos', 'tres', 'cuatro', 'cinco', 'seis', 'siete', 'ocho', 'nueve', 'diez', 'once', 'doce', 'trece', 'catorce', 'quince', 'veinte',
            'treinta', 'cuarenta', 'cincuenta', 'sesenta', 'setenta', 'ochenta', 'noventa', 'cien', 'ciento', 'cientos', 'mil', 'millón', 'millones'],
        'fa' => ['دو', 'سه', 'چهار', 'پنج', 'شش', 'هفت', 'هشت', 'ده', 'بیست', 'سی', 'صد', 'هزار', 'میلیون'],
        'hi' => ['तीन', 'चार', 'पांच', 'पाँच', 'छह', 'छः', 'सात', 'आठ', 'नौ', 'दस', 'बीस', 'सौ', 'हज़ार', 'हजार', 'लाख', 'करोड़'],
        'bn' => ['দুই', 'তিন', 'চার', 'পাঁচ', 'ছয়', 'সাত', 'আট', 'দশ', 'বিশ', 'একশ', 'শত', 'হাজার', 'লাখ', 'কোটি'],
    ];

    /** الأرقام الهندية والفارسية أرقاماً لاتينية */
    public static function latinDigits(string $text): string
    {
        return strtr($text, self::DIGITS);
    }

    /** أرقام لاتينية، والفاصلة العشرية العربية نقطة، وفواصل الآلاف (٬ , والمسافة الضيقة والرفيعة) قبل ثلاث خانات تُحذف */
    public static function normalize(string $text): string
    {
        $t = str_replace('٫', '.', self::latinDigits($text));

        return preg_replace('/(?<=\d)[٬,\x{202F}\x{2009}](?=\d{3}(?!\d))/u', '', $t) ?? $t;
    }

    /**
     * الأرقام مجموعةً متعددة مرتبة، بلا أصفار بادئة (07 = 7، و0 يبقى 0).
     *
     * @return list<string>
     */
    public static function extract(string $text): array
    {
        preg_match_all('/\d+(?:\.\d+)?/', self::normalize($text), $m);
        $out = array_map(function (string $n) {
            [$int, $frac] = array_pad(explode('.', $n, 2), 2, null);
            $int = ltrim($int, '0');
            $int = $int === '' ? '0' : $int;

            return $frac === null ? $int : $int.'.'.$frac;
        }, $m[0]);
        sort($out, SORT_STRING);

        return $out;
    }

    /** بلا لغة هدف: الإنجليزية والعربية وحدهما، فكلمات لغة تتصادم مع كلمات أخرى («on» التركية عشرة) */
    private const DEFAULT_LANGS = ['en', 'ar'];

    /**
     * كلمات أرقام مكتوبة بالحروف في النص، بلغة الهدف وحدها إن عُرفت (والخادم يمررها دائماً)، وإلا الإنجليزية والعربية.
     *
     * @return list<string>
     */
    public static function spelledWords(string $text, ?string $lang = null): array
    {
        $found = [];
        $langs = $lang !== null && isset(self::WORDS[$lang]) ? [$lang] : self::DEFAULT_LANGS;
        foreach (array_intersect_key(self::WORDS, array_flip($langs)) as $code => $words) {
            // العربية: حرف عطف أو جر و«ال» قبل الكلمة (والخمسة، بخمسين)
            $prefix = $code === 'ar' ? '(?:[وفبلك]?(?:ال)?)' : '';
            if (preg_match_all('/(?<![\p{L}\p{M}\p{N}])'.$prefix.'('.implode('|', array_map(fn ($w) => preg_quote($w, '/'), $words)).')(?![\p{L}\p{M}\p{N}])/iu', $text, $m)) {
                foreach ($m[1] as $word) {
                    $found[mb_strtolower($word)] = true;
                }
            }
        }

        return array_keys($found);
    }

    public static function hasSpelledNumbers(string $text, ?string $lang = null): bool
    {
        return self::spelledWords($text, $lang) !== [];
    }

    /**
     * ok: المجموعتان متساويتان ولا رقم بالحروف في الترجمة.
     *
     * @return array{ok: bool, missing: list<string>, extra: list<string>, spelled: bool, spelled_words: list<string>, source: list<string>, translated: list<string>}
     */
    public static function compare(string $source, string $translated, ?string $lang = null): array
    {
        $a = self::extract($source);
        $b = self::extract($translated);
        $missing = self::minus($a, $b);
        $extra = self::minus($b, $a);
        $words = self::spelledWords($translated, $lang);

        return [
            'ok' => $missing === [] && $extra === [] && $words === [],
            'missing' => $missing,
            'extra' => $extra,
            'spelled' => $words !== [],
            'spelled_words' => $words,
            'source' => $a,
            'translated' => $b,
        ];
    }

    /** @return list<string> ما في $a وليس في $b بتعدده */
    private static function minus(array $a, array $b): array
    {
        $left = array_count_values($b);
        $out = [];
        foreach ($a as $n) {
            if (($left[$n] ?? 0) > 0) {
                $left[$n]--;
            } else {
                $out[] = $n;
            }
        }

        return $out;
    }
}
