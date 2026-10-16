<?php

namespace App\Services\WhatsApp;

use App\Models\QuoteRequest;
use App\Models\WaAutoRule;
use App\Models\WaBotSetting;
use App\Models\WaBotUsage;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Support\FeatureFlags;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * الردود الحتمية داخل BotEngine::reply (E37): بعد كل البوابات (الإيقاف، والوارد المعلّق، والبوت والنافذة، والحداثة،
 * وطلب الحذف وطلب الموظف، وحد المعدّل) وقبل النموذج وقاطع مزوّده، فتبقى تجيب أثناء عطل المزوّد. لا تعمل للمساعد
 * الإداري (يعود قبلها)، ولا مع طلب تسعير مفتوح خلال 24 ساعة، ولا لأرقام الموظفين، ولا على رسالة أطول من حد القاعدة.
 *
 * تفشل مغلقة: أي استثناء = لا رد من القاعدة، ويكمل المسار إلى النموذج. الوضع shadow (الافتراضي) يقيّم ويعدّ ولا يرسل.
 * الرد عبر WhatsAppChannel بالمصدر rule (الأقفال وOutboundPolicy كالبوت، والدفتر بمفتاح SendKey::rule).
 */
final class AutoRuleEngine
{
    public const MODES = ['shadow', 'live'];

    /** الحالات المفتوحة لطلب التسعير (E07/E17): القاعدة لا تقطع حوار حجز جارياً */
    private const OPEN_QUOTE = ['new', 'priced', 'sent'];

    private static ?bool $ready = null;

    public static function mode(?WaBotSetting $s = null): string
    {
        $mode = (string) (($s ?? WaBotSetting::current())->auto_rules_mode ?? 'shadow');

        return in_array($mode, self::MODES, true) ? $mode : 'shadow';
    }

    /** الجدول موجود (بين النشر والهجرة 2026_10_14_000037 لا يوجد، فلا قاعدة) */
    public static function ready(): bool
    {
        return self::$ready ??= Schema::hasTable('wa_auto_rules');
    }

    /** للاختبارات والهجرات داخل العملية نفسها */
    public static function flush(): void
    {
        self::$ready = null;
    }

    /**
     * @param  Collection<int, WaMessage>|null  $pending  الوارد المعلّق (بعد العلامة) كما يقرؤه البوت
     * @return array{sent: bool, reason: ?string}|null null: لا رد من قاعدة، فيرد النموذج كالعادة
     */
    public static function tryReply(WaConversation $conv, WaBotSetting $s, ReplyRun $run, string $pending, ?Collection $messages = null): ?array
    {
        if (!FeatureFlags::on('auto_rules')) {
            return null;
        }

        $rule = null;
        try {
            if (!self::ready() || self::openQuote($conv)) {
                return null;
            }
            $rules = WaAutoRule::active()->get();
            if ($rules->isEmpty()) {
                return null;
            }
            $hit = AutoRuleMatcher::firstMatch($rules, AutoRuleContext::forConversation($conv, $messages ?? collect(), $pending));
            if ($hit === null) {
                return null;
            }
            $rule = $hit['rule'];

            // (أ) لغة العميل: بلا نص إنجليزي يجيب النموذج من كتب بالإنجليزية. ومتغير فارغ الآن يلغي القاعدة
            $text = RuleTextGuard::render($rule, HandoffGuard::isLatin($pending));
            if ($text === null) {
                return null;
            }

            // (ب) التهدئة لكل (قاعدة، محادثة): حجز قبل الإرسال، فلا يتكرر الرد الثابت ولا عند إعادة الوظيفة
            if (!Cache::add("wa:rule:cd:{$rule->id}:{$conv->id}", 1, now()->addMinutes(max(1, (int) $rule->cooldown_minutes)))) {
                return null;
            }

            // (ج) الظل: يُعدّ ولا يُرسل، والنموذج يرد كالمعتاد. التحليلات تقرأ live وحده
            if (self::mode($s) !== 'live') {
                self::usage($conv, 'shadow', ['auto_rule:'.$rule->id]);
                WaAutoRule::whereKey($rule->id)->toBase()->increment('shadow_hits');
                WaLog::info('auto_rule.shadow', ['conv_id' => $conv->id, 'rule' => $rule->id]);

                return null;
            }

            // (د) الحي: الرد يغطي كل الوارد الحالي (كما يغطيه رد النموذج)
            $run->uptoId = max($run->uptoId, (int) $conv->messages()->where('direction', 'in')->max('id'));
            if ($rule->tag_topic) {
                TopicRouter::tag($conv, (string) $rule->tag_topic, 'rule');
            }
            // ردّ موظف أو أطفأ البوت أثناء التشغيل؟ لا رد، ولا تتحرك العلامة
            if ($drop = $run->mayDeliver($conv)) {
                self::usage($conv, 'gate', [$drop]);
                WaLog::info('bot.reply.dropped', ['conv_id' => $conv->id, 'reason' => $drop, 'rule' => $rule->id]);

                return ['sent' => false, 'reason' => $drop];
            }
        } catch (\Throwable $e) {
            WaLog::error('auto_rule_engine', ['conv_id' => $conv->id, 'rule' => $rule?->id, 'type' => class_basename($e)]);

            return null;
        }

        // بعد هذا السطر خرج الرد (أو حُسم): لا عودة للنموذج مهما حدث في الحساب
        $res = WhatsAppChannel::sendText($conv, $text, 'rule', sendKey: SendKey::rule($conv, $run->uptoId));
        try {
            if ($res['ok']) {
                BotEngine::served($conv, $run);
                self::usage($conv, 'live', ['auto_rule:'.$rule->id]);
                WaAutoRule::whereKey($rule->id)->toBase()->increment('hits', 1, ['last_hit_at' => now()]);
            } elseif (!empty($res['unknown']) && ReplyState::available()) {
                // دفتر الإرسال (E19): رد لم يتأكد وصوله لا يُعاد آلياً، فالمحادثة لموظف
                ReplyState::markUnserved($conv->fresh() ?? $conv, 'send_unknown');
            }
        } catch (\Throwable $e) {
            WaLog::error('auto_rule_engine', ['conv_id' => $conv->id, 'rule' => $rule->id, 'type' => class_basename($e)]);
        }

        return ['sent' => (bool) $res['ok'], 'reason' => $res['ok'] ? 'auto_rule' : $res['error']];
    }

    /** طلب تسعير مفتوح خلال 24 ساعة (احتياط: التحويل يطفئ البوت للمحادثة أصلاً) */
    public static function openQuote(WaConversation $conv, ?\DateTimeInterface $at = null): bool
    {
        $at ??= now();

        return QuoteRequest::where('conversation_id', $conv->id)->whereIn('status', self::OPEN_QUOTE)
            ->where('created_at', '>=', Carbon::instance($at)->subDay())
            ->where('created_at', '<=', $at)->exists();
    }

    /** صف استهلاك بلا نداء ولا كلفة (model=rule لا سعر له)، والوسم auto_rule:{id} */
    private static function usage(WaConversation $conv, string $source, array $tools): void
    {
        try {
            WaBotUsage::create([
                'conversation_id' => $conv->id, 'source' => $source, 'model' => 'rule', 'calls' => 0,
                'prompt_tokens' => 0, 'output_tokens' => 0, 'cache_read_tokens' => 0, 'cache_write_tokens' => 0,
                'tools_used' => $tools, 'leaked' => false, 'latency_ms' => 0, 'created_at' => now(),
            ]);
        } catch (\Throwable) {
            // القياس ليس حرجاً
        }
    }
}
