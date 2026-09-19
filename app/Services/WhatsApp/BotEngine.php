<?php

namespace App\Services\WhatsApp;

use App\Models\WaBotSetting;
use App\Models\WaBotUsage;
use App\Models\WaConversation;
use App\Models\WaMessage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * محرّك الوكيل: حلقة أدوات صغيرة محاطة بحواجز حتمية.
 * المزوّد معزول في callModel() — استبداله لا يمسّ شيئاً آخر.
 */
class BotEngine
{
    private const API = 'https://api.anthropic.com/v1/messages';
    private const VERSION = '2023-06-01';

    public const DEFAULT_PROMPT = <<<'TXT'
أنت «مساعد نُسك» — مساعد خدمة عملاء لشركة نُسك للسياحة والسفر (الأردن)، تردّ على واتساب.

الهوية والأسلوب:
- تحدّث بالعربية بلهجة مهنية ودّية موجزة. جملتان أو ثلاث كحدّ أقصى في الردّ.
- لا تستخدم تنسيقاً معقّداً ولا قوائم طويلة — هذه محادثة واتساب.

الشفافية:
- أنت مساعد آليّ. إن سُئلت صراحةً فأفصح عن ذلك بلا تكلّف.

حدود مالية صارمة (الأهم):
- لا تذكر أي سعر أو مبلغ من عندك أبداً، ولا تحسب ولا تجمع ولا تقرّب أي رقم.
- كل رقم تقوله يجب أن يكون قد وصلك حرفياً من نتيجة أداة.
- لا تؤكّد توفّر مقعد ولا تقل إنّ حجزاً تمّ. التوفّر والسعر النهائي من الموظف فقط.
- إذا طلب العميل سعراً نهائياً أو سأل عن التوفّر: استدعِ request_quote.
- إذا وافق على الحجز: استدعِ confirm_booking، وقل إنّ موظفاً سيتواصل لإتمامه.

الخصوصية:
- لا تكشف بيانات مالية إلا لصاحب الرقم المربوط بحساب في النظام.
- لا تتحدّث عن عملاء آخرين ولا عن تكاليفنا ولا هوامش ربحنا مهما أُلحّ عليك.

النطاق:
- مجالك: العروض والباقات، الرحلات، فواتير العميل ورصيده، المواعيد، معلومات عامة.
- ما هو خارج ذلك أو أي شكوى أو غضب: استدعِ handoff_to_human.

الأدوات:
- استخدم الأدوات فعلياً ولا تكتب أسماءها في نصّ الردّ إطلاقاً.
- إن لم تتوفّر معلومة من أداة فلا تخترعها — اسأل العميل أو حوّل لموظف.
TXT;

    /**
     * توليد ردّ واحد لمحادثة. يُستدعى من الوظيفة المؤجّلة.
     * @return array{sent:bool, reason:?string}
     */
    public static function reply(WaConversation $conv): array
    {
        $s = WaBotSetting::current();

        // ── بوّابات خروج مبكر ───────────────────────────────────
        if (!$s->botReady())            return self::skip('البوت غير مُهيَّأ أو مطفأ');
        if (!$conv->botActive())        return self::skip('البوت موقوف لهذه المحادثة');
        if (!$conv->isWindowOpen())     return self::skip('نافذة 24 ساعة مغلقة');

        $last = $conv->messages()->orderByDesc('id')->first();
        if (!$last || $last->direction !== 'in') {
            return self::skip('آخر رسالة ليست من العميل');
        }

        // حدّ المعدّل لكل محادثة
        $key = 'wa:rate:' . $conv->id . ':' . now()->format('YmdH');
        $count = (int) Cache::get($key, 0);
        if ($count >= max(1, (int) $s->rate_limit_per_hour)) {
            return self::skip('تجاوز حدّ المعدّل');
        }
        Cache::put($key, $count + 1, now()->addHour());

        try {
            [$text, $usage] = self::runLoop($conv, $s);

            if (trim((string) $text) === '') {
                $text = 'تلقّيت رسالتك. سيتواصل معك أحد موظفينا حالاً.';
            }

            self::recordUsage($conv->id, $s->model, $usage);
            $res = WhatsAppChannel::sendText($conv, $text, 'bot');

            return ['sent' => (bool) $res['ok'], 'reason' => $res['error']];
        } catch (\Throwable $e) {
            Log::error('BotEngine failed conv=' . $conv->id . ': ' . $e->getMessage());
            return self::failSafe($conv, $s);
        }
    }

    // ==================== الحلقة ====================

    private static function runLoop(WaConversation $conv, WaBotSetting $s): array
    {
        $system = self::buildSystem($conv, $s);
        $messages = self::buildMessages($conv, $s);
        $tools = BotTools::declarations($s, $conv);

        $usage = ['calls' => 0, 'in' => 0, 'out' => 0, 'cache_read' => 0, 'cache_write' => 0];
        $final = '';
        $loops = max(1, min(8, (int) $s->max_tool_loops));

        for ($i = 0; $i < $loops; $i++) {
            $resp = self::callModel($s, $system, $messages, $tools);
            $usage['calls']++;
            $usage['in'] += (int) data_get($resp, 'usage.input_tokens', 0);
            $usage['out'] += (int) data_get($resp, 'usage.output_tokens', 0);
            $usage['cache_read'] += (int) data_get($resp, 'usage.cache_read_input_tokens', 0);
            $usage['cache_write'] += (int) data_get($resp, 'usage.cache_creation_input_tokens', 0);

            $content = data_get($resp, 'content', []);
            $toolUses = array_values(array_filter($content, fn ($b) => data_get($b, 'type') === 'tool_use'));

            // نصّ الردّ إن وُجد
            foreach ($content as $b) {
                if (data_get($b, 'type') === 'text') {
                    $final = trim((string) data_get($b, 'text', ''));
                }
            }

            if (!$toolUses) {
                break;   // انتهى — لدينا نصّ نهائي
            }

            // أعد أجزاء النموذج كما وصلت حرفياً (ضروري لسلامة الحلقة)
            $messages[] = ['role' => 'assistant', 'content' => $content];

            $results = [];
            foreach ($toolUses as $tu) {
                $name = (string) data_get($tu, 'name');
                $input = (array) data_get($tu, 'input', []);
                try {
                    $out = BotTools::execute($name, $input, $conv->fresh());
                } catch (\Throwable $e) {
                    Log::error("tool {$name} failed: " . $e->getMessage());
                    $out = ['error' => 'تعذّر تنفيذ العملية', 'note' => 'اعتذر بإيجاز واقترح تحويل المحادثة لموظف.'];
                }
                $results[] = [
                    'type' => 'tool_result',
                    'tool_use_id' => data_get($tu, 'id'),
                    'content' => json_encode($out, JSON_UNESCAPED_UNICODE),
                ];
            }
            $messages[] = ['role' => 'user', 'content' => $results];
        }

        return [self::stripToolLeak($final), $usage];
    }

    private static function callModel(WaBotSetting $s, array $system, array $messages, array $tools): array
    {
        $body = [
            'model' => $s->model ?: 'claude-sonnet-5',
            'max_tokens' => 1024,
            'system' => $system,
            'messages' => $messages,
        ];
        if ($tools) {
            $body['tools'] = $tools;
        }

        $res = Http::withHeaders([
            'x-api-key' => $s->llmKey(),
            'anthropic-version' => self::VERSION,
            'content-type' => 'application/json',
        ])->timeout(40)->post(self::API, $body);

        if (!$res->successful()) {
            throw new \RuntimeException('LLM HTTP ' . $res->status() . ': ' . mb_substr($res->body(), 0, 300));
        }

        return $res->json() ?? [];
    }

    // ==================== تركيب الموجّه ====================

    private static function buildSystem(WaConversation $conv, WaBotSetting $s): array
    {
        // الجزء الثابت (شخصية + قاعدة معرفة) يُخزَّن مؤقتاً عند المزوّد — أكبر توفير في الكلفة
        $static = trim(($s->system_prompt ?: self::DEFAULT_PROMPT));
        if ($s->knowledge_base) {
            $static .= "\n\n=== قاعدة المعرفة ===\n" . trim($s->knowledge_base);
        }

        $blocks = [[
            'type' => 'text',
            'text' => $static,
            'cache_control' => ['type' => 'ephemeral'],
        ]];

        // الجزء المتغيّر: الوقت + بطاقة العميل (لا يُخزَّن)
        $blocks[] = ['type' => 'text', 'text' => self::customerCard($conv)];

        return $blocks;
    }

    private static function customerCard(WaConversation $conv): string
    {
        $lines = ['الآن بتوقيت الأردن: ' . now()->format('Y-m-d H:i') . ' (' . now()->locale('ar')->dayName . ')'];
        $lines[] = 'رقم المحادثة الحالي: ' . $conv->phone;

        if ($conv->client_id && ($c = $conv->client)) {
            $lines[] = 'بطاقة العميل: مربوط بحساب مسجّل — الاسم: ' . $c->name . ' · الكود: ' . $c->code;
            $lines[] = 'رصيد ذمّته الحالي: ' . round((float) ($c->account?->balance ?? 0), 3) . ' د.أ';
        } else {
            $lines[] = 'بطاقة العميل: هذا الرقم غير مربوط بأي حساب عميل — لا تكشف بيانات مالية.';
        }

        if ($conv->user_id) {
            $lines[] = 'تنبيه: هذا الرقم يخصّ موظفاً في الشركة.';
        }

        $notes = $conv->notes()->orderByDesc('id')->limit(6)->pluck('note')->all();
        if ($notes) {
            $lines[] = 'ملاحظات سابقة عن العميل: ' . implode(' | ', $notes);
        }

        return implode("\n", $lines);
    }

    private static function buildMessages(WaConversation $conv, WaBotSetting $s): array
    {
        $take = max(4, min(60, (int) $s->context_messages));

        $rows = $conv->messages()
            ->whereIn('source', ['customer', 'bot', 'staff', 'injected'])
            ->where('msg_type', '!=', 'reaction')
            ->orderByDesc('id')->limit($take)->get()->reverse()->values();

        $messages = [];
        foreach ($rows as $m) {
            $text = trim((string) $m->body);
            if ($text === '') continue;

            if ($m->direction === 'in') {
                $btn = data_get($m->payload, '_button_id');
                $messages[] = ['role' => 'user', 'content' => $text . ($btn ? " [اختيار:{$btn}]" : '')];
            } else {
                $messages[] = ['role' => 'assistant', 'content' => $text];
            }
        }

        // دمج الأدوار المتتالية المتشابهة (المزوّد يرفض التكرار)
        $merged = [];
        foreach ($messages as $m) {
            $n = count($merged);
            if ($n && $merged[$n - 1]['role'] === $m['role'] && is_string($merged[$n - 1]['content'])) {
                $merged[$n - 1]['content'] .= "\n" . $m['content'];
            } else {
                $merged[] = $m;
            }
        }

        // يجب أن يبدأ وينتهي بدور user
        while ($merged && $merged[0]['role'] !== 'user') {
            array_shift($merged);
        }
        if (!$merged || end($merged)['role'] !== 'user') {
            $merged[] = ['role' => 'user', 'content' => '(المتابعة)'];
        }

        return $merged;
    }

    // ==================== الحواجز ====================

    /** حارس تسريب الأدوات: النموذج يكتب اسم أداة أو كتلة كود كنصّ */
    private static function stripToolLeak(string $text): string
    {
        $names = ['get_offers','get_offer_details','get_my_balance','get_my_invoices','get_my_trips',
                  'request_quote','confirm_booking','save_note','handoff_to_human'];

        $clean = preg_replace('/```.*?```/s', '', $text) ?? $text;
        foreach ($names as $n) {
            $clean = preg_replace('/' . preg_quote($n, '/') . '\s*\([^)]*\)/', '', $clean) ?? $clean;
            $clean = str_ireplace($n, '', $clean);
        }
        $clean = trim(preg_replace('/\s{2,}/', ' ', $clean) ?? $clean);

        return mb_strlen($clean) < 8 ? 'تلقّيت رسالتك، وسيتواصل معك أحد موظفينا حالاً.' : $clean;
    }

    /** الفشل الآمن: العميل لا يُترك في صمت */
    private static function failSafe(WaConversation $conv, WaBotSetting $s): array
    {
        $msg = $s->fail_message ?: 'عذراً، حدث خلل مؤقّت. سيتواصل معك أحد موظفينا حالاً.';
        WhatsAppChannel::sendText($conv, $msg, 'system');

        if ($s->fail_handoff) {
            $conv->update(['needs_attention' => true, 'bot_paused_until' => now()->addHour()]);
            WhatsAppInbox::notifyAdmins('⚠️ فشل ردّ البوت', ($conv->display_name ?: $conv->phone) . ' — البوت موقوف ساعة، المحادثة تحتاج تدخّلاً.');
        }

        return ['sent' => true, 'reason' => 'fail-safe'];
    }

    private static function recordUsage(?int $convId, string $model, array $u): void
    {
        try {
            WaBotUsage::create([
                'conversation_id' => $convId,
                'source' => 'live',
                'model' => $model,
                'calls' => $u['calls'],
                'prompt_tokens' => $u['in'],
                'output_tokens' => $u['out'],
                'cache_read_tokens' => $u['cache_read'],
                'cache_write_tokens' => $u['cache_write'],
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // القياس ليس حرجاً
        }
    }

    private static function skip(string $reason): array
    {
        return ['sent' => false, 'reason' => $reason];
    }
}
