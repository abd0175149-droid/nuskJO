<?php

namespace App\Services\WhatsApp;

use App\Models\WaAutoRule;
use Illuminate\Support\Facades\Cache;

/**
 * مطابقة قواعد الرد الحتمية (E37، C116 مكيَّفاً): صيغة صغيرة لا محرك فلاتر. دوال نقية تخدم مسار الرد والمعاينة
 * والمحاكاة معاً. القيم حرفية دائماً على كلمات كاملة (لا تعابير نمطية من المستخدم، فلا ReDoS)، والقاعدة التالفة
 * تُتخطى وحدها وتُعلَّم، ولا توقف غيرها.
 */
final class AutoRuleMatcher
{
    /** مفاتيح الشروط المسموحة (كلها AND، وكل مفتاح اختياري) */
    public const CONDITIONS = ['msg_types', 'linked', 'topics_any', 'has_referral', 'referral_source_ids', 'exclude_staff'];

    /** الصوت مستبعد افتراضياً: التفريغ قد يخطئ */
    public const DEFAULT_MSG_TYPES = ['text', 'interactive', 'button'];

    public const MSG_TYPES = ['text', 'interactive', 'button', 'audio'];

    public const LINKED = ['any', 'yes', 'no'];

    /**
     * النص المطبَّع: جدول الأحرف والأرقام نفسه في البحث (TextNormalizer::forSearch، body_norm من E11: بلا تشكيل،
     * والهمزات والتاء المربوطة والألف المقصورة موحّدة، والأرقام الهندية والفارسية لاتينية)، ثم الترقيم مسافات
     * والمسافات مضغوطة كما في HandoffGuard (فـ«/الموقع» تساوي «الموقع»). بلا trim() بقائمة محارف.
     */
    public static function normalize(string $text): string
    {
        return HandoffGuard::normalize((string) TextNormalizer::forSearch($text));
    }

    /** @return list<?string>|null القيم مطبَّعة (القيمة غير النصية null فتُعدّ القاعدة تالفة) */
    public static function normalizeAll(mixed $patterns): ?array
    {
        if (!is_array($patterns)) {
            return null;
        }

        return array_values(array_map(fn ($p) => is_string($p) ? self::normalize($p) : null, $patterns));
    }

    public static function words(string $norm): int
    {
        return $norm === '' ? 0 : count(explode(' ', $norm));
    }

    /** @throws \InvalidArgumentException قاعدة تالفة (السبب في الرسالة، بلا نص عميل) */
    public static function assertValid(WaAutoRule $r): void
    {
        if (!in_array($r->match_type, WaAutoRule::MATCH_TYPES, true)) {
            throw new \InvalidArgumentException('نوع مطابقة غير معروف');
        }
        $patterns = $r->patterns_norm;
        if (!is_array($patterns) || $patterns === [] || count($patterns) > WaAutoRule::MAX_PATTERNS) {
            throw new \InvalidArgumentException('قيم المطابقة ليست قائمة صالحة');
        }
        foreach ($patterns as $p) {
            if (!is_string($p) || $p === '') {
                throw new \InvalidArgumentException('قيمة فارغة بعد التطبيع');
            }
        }
        $c = $r->conditions ?? [];
        if (!is_array($c) || count($c) > WaAutoRule::MAX_CONDITION_KEYS || array_diff(array_keys($c), self::CONDITIONS) !== []) {
            throw new \InvalidArgumentException('شروط غير معروفة أو كثيرة');
        }
        if (isset($c['msg_types']) && (!is_array($c['msg_types']) || $c['msg_types'] === [] || array_diff($c['msg_types'], self::MSG_TYPES) !== [])) {
            throw new \InvalidArgumentException('أنواع رسائل غير معروفة');
        }
        if (isset($c['linked']) && !in_array($c['linked'], self::LINKED, true)) {
            throw new \InvalidArgumentException('شرط الربط غير معروف');
        }
        if (isset($c['topics_any']) && (!is_array($c['topics_any']) || array_filter($c['topics_any'], fn ($t) => !is_string($t) || !TopicRouter::valid($t)) !== [])) {
            throw new \InvalidArgumentException('موضوع غير معروف في الشروط');
        }
        if (isset($c['referral_source_ids']) && !is_array($c['referral_source_ids'])) {
            throw new \InvalidArgumentException('معرّفات الإعلانات ليست قائمة');
        }
        if ($r->tag_topic !== null && !TopicRouter::valid((string) $r->tag_topic)) {
            throw new \InvalidArgumentException('وسم موضوع غير معروف');
        }
        if (!in_array($r->reply_kind ?? 'text', WaAutoRule::REPLY_KINDS, true)) {
            throw new \InvalidArgumentException('نوع رد غير متاح');
        }
    }

    /** المطابقة على كلمات كاملة: exact يساوي قيمة، وcontains كل القيم (AND)، وany_of قيمة واحدة على الأقل (OR) */
    public static function match(WaAutoRule $r, string $norm): bool
    {
        $haystack = ' '.$norm.' ';
        $has = fn (string $p) => str_contains($haystack, ' '.$p.' ');
        $patterns = (array) $r->patterns_norm;

        return match ($r->match_type) {
            'exact' => in_array($norm, $patterns, true),
            'contains' => array_filter($patterns, fn ($p) => !$has($p)) === [],
            'any_of' => array_filter($patterns, $has) !== [],
            default => throw new \InvalidArgumentException('نوع مطابقة غير معروف'),
        };
    }

    public static function passesConditions(WaAutoRule $r, AutoRuleContext $ctx): bool
    {
        $c = (array) ($r->conditions ?? []);

        if (array_diff($ctx->msgTypes, $c['msg_types'] ?? self::DEFAULT_MSG_TYPES) !== []) {
            return false;
        }
        $linked = $c['linked'] ?? 'any';
        if (($linked === 'yes' && !$ctx->linked) || ($linked === 'no' && $ctx->linked)) {
            return false;
        }
        if (!empty($c['topics_any']) && array_intersect($c['topics_any'], $ctx->topics) === []) {
            return false;
        }
        if (array_key_exists('has_referral', $c) && (bool) $c['has_referral'] !== ($ctx->referrals !== [])) {
            return false;
        }
        if (!empty($c['referral_source_ids'])) {
            $ids = array_map('strval', array_filter(array_column($ctx->referrals, 'source_id'), 'is_scalar'));
            if (array_intersect(array_map('strval', $c['referral_source_ids']), $ids) === []) {
                return false;
            }
        }

        return !(($c['exclude_staff'] ?? true) && $ctx->staff);
    }

    /** حكم قاعدة واحدة: match | no_match | too_long | conditions | invalid */
    public static function evaluate(WaAutoRule $r, AutoRuleContext $ctx): string
    {
        try {
            self::assertValid($r);
            if (!self::match($r, $ctx->norm)) {
                return 'no_match';
            }
            if ($ctx->words > (int) $r->max_words) {
                return 'too_long';
            }

            return self::passesConditions($r, $ctx) ? 'match' : 'conditions';
        } catch (\Throwable $e) {
            self::markInvalid($r, $e);

            return 'invalid';
        }
    }

    /**
     * أول قاعدة تطابق بالأولوية، أو null. كل قاعدة تُقيَّم وحدها: التالفة تُتخطى ويُكتب سببها.
     *
     * @param  iterable<WaAutoRule>  $rules
     * @param  array<int, string>|null  $reasons  حكم كل قاعدة قُيّمت (للمعاينة)
     * @return array{rule: WaAutoRule, reason: string}|null
     */
    public static function firstMatch(iterable $rules, AutoRuleContext $ctx, ?array &$reasons = null): ?array
    {
        $reasons = [];
        foreach ($rules as $rule) {
            $reasons[$rule->id] = self::evaluate($rule, $ctx);
            if ($reasons[$rule->id] === 'match') {
                return ['rule' => $rule, 'reason' => 'match'];
            }
        }

        return null;
    }

    /** last_error بلا لمس updated_at، وسطر سجل واحد في اليوم لكل قاعدة */
    private static function markInvalid(WaAutoRule $r, \Throwable $e): void
    {
        $message = mb_substr('قاعدة تالفة: '.($e instanceof \InvalidArgumentException ? $e->getMessage() : class_basename($e)), 0, 191);
        try {
            if ($r->exists && $r->last_error !== $message) {
                WaAutoRule::whereKey($r->id)->toBase()->update(['last_error' => $message]);
                $r->setAttribute('last_error', $message);
                $r->syncOriginalAttribute('last_error');
            }
            if (Cache::add('wa:rule:err:'.$r->id.':'.now()->format('Ymd'), 1, now()->addDay())) {
                WaLog::error('auto_rule_invalid', ['rule' => $r->id]);
            }
        } catch (\Throwable) {
            // العلامة لا تُسقط تقييم غيرها
        }
    }
}
