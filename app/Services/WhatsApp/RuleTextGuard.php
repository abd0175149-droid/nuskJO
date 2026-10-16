<?php

namespace App\Services\WhatsApp;

use App\Models\WaAutoRule;
use App\Services\CompanyInfo;
use Illuminate\Support\Facades\Cache;

/**
 * حارس نص الرد الحتمي (E37): نص ثابت يصل كل من يسأل، فلا سعر فيه ولا بيانات دفع ولا رقم هاتف مكتوب ولا رابط داخلي،
 * ولا طلب لصور الجوازات أو الهويات عبر واتساب (AMENDMENTS، E14). يُطبَّق عند الحفظ، ومرة ثانية لحظة الإرسال فلا تمر
 * قاعدة قديمة حُفظت قبل تشديده. البيانات الثابتة من متغيرات الشركة وحدها، والمصدر نفسه الذي يقرؤه البوت
 * (CompanyInfo)، فلا تتضارب الإجابتان. كل المعالجة بـ mb_* وpreg /u.
 */
final class RuleTextGuard
{
    /** المتغير => حقله في CompanyInfo. قائمة أضيق من الردود الجاهزة: لا متغيرات عميل ولا مالية */
    public const VARIABLES = [
        'company.name' => 'name',
        'company.phone' => 'phone',
        'company.address' => 'address',
        'company.hours' => 'hours',
        'company.email' => 'email',
        'company.facebook' => 'facebook',
    ];

    public const VARIABLE_LABELS = [
        'company.name' => 'اسم الشركة',
        'company.phone' => 'هاتف الشركة',
        'company.address' => 'عنوان المكتب',
        'company.hours' => 'مواعيد الدوام',
        'company.email' => 'البريد الإلكتروني',
        'company.facebook' => 'صفحة فيسبوك',
    ];

    private const CURRENCY = '(?:دينار|دنانير|د\.?\s?أ|JOD|JD|ريال|ر\.?\s?س|SAR|درهم|AED|دولار|USD|\$|€|EUR|يورو)';

    private const NUMBER = '\d+(?:[.,٫٬]\d+)*';

    /** ألفاظ السعر بعد التطبيع (همزات موحّدة، بلا تطويل) */
    private const PRICE_WORDS = '(?:سعر|بسعر|السعر|بالسعر|اسعار|الاسعار|باسعار|يبدا من|تبدا من|تبدا ب|فقط ب|خصم|الخصم|بخصم|تخفيض|التخفيض|بتخفيض)';

    /** @return list<string> رسائل عربية للنموذج؛ فارغة = مقبول */
    public static function errors(string $text): array
    {
        $out = [];
        if (trim($text) === '') {
            return ['النص فارغ.'];
        }
        if (mb_strlen($text) > WaAutoRule::MAX_REPLY) {
            $out[] = 'النص أطول من '.WaAutoRule::MAX_REPLY.' حرفاً.';
        }

        foreach (self::variables($text) as $name) {
            if (!isset(self::VARIABLES[$name])) {
                $out[] = "متغير غير معروف: {{$name}}. المسموح: ".implode('، ', array_map(fn ($v) => '{'.$v.'}', array_keys(self::VARIABLES))).'.';
            }
        }

        // الأرقام الهندية لاتينية، والمتغيرات خارج الفحص (قيمها من إعدادات الشركة)
        $plain = preg_replace('/\{[^{}]*\}/u', ' ', QuickReplyText::latinDigits($text)) ?? $text;
        $norm = AutoRuleMatcher::normalize($plain);

        $currency = (bool) preg_match('/'.self::NUMBER.'\s*'.self::CURRENCY.'/iu', $plain) || (bool) preg_match('/'.self::CURRENCY.'\s*'.self::NUMBER.'/iu', $plain);
        $priceWord = (bool) preg_match('/(?:^|\s)'.self::PRICE_WORDS.'(?:\s+[^\s\d]+){0,2}\s+\d/u', $norm) || (bool) preg_match('/\d\s*[%٪]|[%٪]\s*\d/u', $plain);
        if ($currency || $priceWord) {
            $out[] = 'لا أسعار ولا خصومات بأرقام في الرد الثابت: السعر من العروض أو من الموظف.';
        }

        if (preg_match('/JO\d{2}[A-Z0-9]{4}|cliq|كليك|اسم مستعار للتحويل|\d{13,19}/iu', $plain)) {
            $out[] = 'لا بيانات دفع في الرد الثابت (حساب أو CliQ أو رقم بطاقة): الدفع يتولاه موظف.';
        }

        preg_match_all('/\+?\d[\d\s\-]*\d/u', $plain, $runs);
        foreach ($runs[0] as $run) {
            if (strlen(preg_replace('/\D/', '', $run) ?? '') >= 7) {
                $out[] = 'لا رقم هاتف مكتوباً في النص: استعمل المتغير {company.phone}.';
                break;
            }
        }

        if (preg_match('#http://#i', $plain)) {
            $out[] = 'الروابط بـ https العامة وحدها.';
        }
        if (preg_match('#https?://[^\s]*/storage/#i', $plain) || preg_match('#https?://\d{1,3}(?:\.\d{1,3}){3}#i', $plain)) {
            $out[] = 'لا روابط لملفات داخلية أو لعنوان IP: روابط https العامة وحدها.';
        }

        if (QuickReplyText::asksForIdDocument($text)) {
            $out[] = 'لا طلب لصور الجوازات أو الهويات عبر واتساب: يتولى الموظف ذلك بالطريق المعتمد.';
        }

        return array_values(array_unique($out));
    }

    /** @return list<string> أسماء المتغيرات {…} كما كُتبت */
    public static function variables(string $text): array
    {
        preg_match_all('/\{\s*([^{}]*?)\s*\}/u', $text, $m);

        return array_values(array_unique($m[1]));
    }

    /**
     * النص النهائي لحظة الإرسال، أو null فتُترك الرسالة للنموذج: لا نص بلغة العميل (لا رد عربي لمن كتب بالإنجليزية)،
     * أو حارس يرفض القالب، أو متغير فارغ الآن (company_hours غير مضبوط مثلاً). سطر سجل واحد في اليوم لكل قاعدة.
     */
    public static function render(WaAutoRule $rule, bool $latin): ?string
    {
        $template = (string) ($latin ? $rule->reply_text_en : $rule->reply_text_ar);
        if (trim($template) === '') {
            return null;
        }
        if (self::errors($template) !== []) {
            self::logOnce($rule, 'auto_rule_text_rejected');

            return null;
        }

        $company = CompanyInfo::all();
        $missing = false;
        $text = preg_replace_callback('/\{\s*([^{}]*?)\s*\}/u', function ($m) use ($company, &$missing) {
            $value = trim((string) ($company[self::VARIABLES[$m[1]] ?? ''] ?? ''));
            if ($value === '') {
                $missing = true;

                return $m[0];
            }

            return $value;
        }, $template) ?? '';

        if ($missing) {
            self::logOnce($rule, 'auto_rule_variable_empty');

            return null;
        }
        $text = trim($text);

        return $text === '' || mb_strlen($text) > WaAutoRule::MAX_REPLY ? null : $text;
    }

    /**
     * قالب نص آلي آخر بالحارس والمتغيرات نفسيهما (E39: المتابعة بعد المكالمة الفائتة): النص النهائي، أو null إن رفضه
     * الحارس أو بقي متغير فارغاً الآن. لا سجل هنا: المستدعي يسجّل بسببه.
     */
    public static function fill(string $template): ?string
    {
        if (trim($template) === '' || self::errors($template) !== []) {
            return null;
        }
        $company = CompanyInfo::all();
        $missing = false;
        $text = preg_replace_callback('/\{\s*([^{}]*?)\s*\}/u', function ($m) use ($company, &$missing) {
            $value = trim((string) ($company[self::VARIABLES[$m[1]] ?? ''] ?? ''));
            if ($value === '') {
                $missing = true;
            }

            return $value;
        }, $template) ?? '';
        $text = trim($text);

        return $missing || $text === '' ? null : $text;
    }

    private static function logOnce(WaAutoRule $rule, string $event): void
    {
        try {
            if (Cache::add("wa:rule:log:{$event}:{$rule->id}:".now()->format('Ymd'), 1, now()->addDay())) {
                WaLog::error($event, ['rule' => $rule->id]);
            }
        } catch (\Throwable) {
            // السجل لا يُسقط الرد
        }
    }
}
