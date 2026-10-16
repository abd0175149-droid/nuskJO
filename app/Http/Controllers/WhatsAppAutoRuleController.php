<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Http\Requests\StoreAutoRuleRequest;
use App\Models\AuditLog;
use App\Models\QuoteRequest;
use App\Models\User;
use App\Models\WaAdminNumber;
use App\Models\WaAutoRule;
use App\Models\WaBotSetting;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\AutoRuleContext;
use App\Services\WhatsApp\AutoRuleEngine;
use App\Services\WhatsApp\AutoRuleMatcher;
use App\Services\WhatsApp\ConversationalComponents;
use App\Services\WhatsApp\RuleTextGuard;
use App\Services\WhatsApp\Settings\SettingsWriter;
use App\Services\WhatsApp\TopicRouter;
use App\Services\WhatsApp\WhatsAppChannel;
use App\Support\FeatureFlags;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * الردود الحتمية (E37): قواعد بنص ثابت يعتمده المالك، تُدار بصلاحية whatsapp.auto_rules (للمدير افتراضياً).
 * القاعدة تُحفظ موقوفة، ولا تُفعَّل قبل أن تُجرَّب («جرّب نصاً») أو تُحاكى على السجل بعد آخر تعديل، وتعديل محتواها يوقفها
 * حتى تُجرَّب من جديد. كل كتابة في سجل التدقيق داخل معاملتها. المكوّنات الرسمية (أوامر وأسئلة البداية) تكتب في ميتا،
 * فهي لصاحب whatsapp.settings وخلف العلم conversational_components.
 */
class WhatsAppAutoRuleController extends Controller
{
    /** المحاكاة: آخر 2000 رسالة نصية واردة خلال 30 يوماً، والقاعدة فوق 15% «واسعة جداً» */
    private const SIMULATE_LIMIT = 2000;

    private const SIMULATE_DAYS = 30;

    private const BROAD_SHARE = 0.15;

    public const MSG_TYPE_LABELS = ['text' => 'نص', 'interactive' => 'اختيار من قائمة', 'button' => 'زر', 'audio' => 'صوت مفرّغ'];

    public function index()
    {
        $this->authorizeRules();
        $s = WaBotSetting::current();
        $rules = AutoRuleEngine::ready() ? WaAutoRule::ordered()->get() : collect();
        $names = User::whereIn('id', $rules->pluck('updated_by')->filter()->unique())->pluck('name', 'id');
        $canSettings = auth()->user()->can('whatsapp.settings');

        return Inertia::render('WhatsApp/AutoRules', [
            'title' => 'الردود الحتمية',
            'rules' => $rules->map(fn (WaAutoRule $r) => self::present($r, $names))->values(),
            'topics' => TopicRouter::TOPICS,
            'variables' => RuleTextGuard::VARIABLE_LABELS,
            'msgTypes' => self::MSG_TYPE_LABELS,
            'limits' => [
                'rules' => WaAutoRule::MAX_RULES, 'patterns' => WaAutoRule::MAX_PATTERNS, 'pattern_len' => WaAutoRule::MAX_PATTERN_LEN,
                'reply' => WaAutoRule::MAX_REPLY, 'condition_keys' => WaAutoRule::MAX_CONDITION_KEYS,
                'prompts' => ConversationalComponents::MAX_PROMPTS, 'prompt_len' => ConversationalComponents::PROMPT_LEN,
                'commands' => ConversationalComponents::MAX_COMMANDS, 'command_len' => ConversationalComponents::COMMAND_LEN,
                'description_len' => ConversationalComponents::DESCRIPTION_LEN,
            ],
            'mode' => AutoRuleEngine::mode($s),
            'flag' => FeatureFlags::state('auto_rules'),
            'settingsVersion' => (int) ($s->settings_version ?? 1),
            'canSettings' => $canSettings,
            'canViewAll' => auth()->user()->can('whatsapp.view_all'),
            // المكوّنات الرسمية: لصاحب whatsapp.settings وحده، ولا رمز ولا رد خام من ميتا في الصفحة
            'automation' => $canSettings ? ConversationalComponents::state($s) + ['enabled' => FeatureFlags::on('conversational_components')] : null,
        ]);
    }

    public function store(StoreAutoRuleRequest $request)
    {
        $data = $request->clean();
        $rule = DB::transaction(function () use ($data) {
            // الحد داخل المعاملة: حفظان متزامنان لا يتجاوزانه
            if (WaAutoRule::count() >= WaAutoRule::MAX_RULES) {
                throw ValidationException::withMessages(['name' => 'الحد '.WaAutoRule::MAX_RULES.' قاعدة: احذف قاعدة لا تحتاجها أولاً.']);
            }
            $rule = WaAutoRule::create($data + [
                'is_active' => false,
                'priority' => min(65000, (int) WaAutoRule::max('priority') + 1),
                'created_by' => auth()->id(),
                'updated_by' => auth()->id(),
            ]);
            AuditLog::log(AuditAction::WaAutoRuleCreate, 'wa_auto_rule', $rule->id, $rule->name, self::snapshot($rule));

            return $rule;
        });

        return back()->with('success', "أُضيفت القاعدة «{$rule->name}» موقوفة: جرّبها بنص ثم فعّلها.");
    }

    public function update(StoreAutoRuleRequest $request, WaAutoRule $rule)
    {
        $before = self::snapshot($rule);
        $rule->fill($request->clean() + ['updated_by' => auth()->id()]);
        // ما تطابقه القاعدة أو ما ترد به تغيّر: تُوقف حتى تُجرَّب من جديد (لا يصل العملاء ما لم يُعايَن)
        $stopped = $rule->is_active && $rule->isDirty(WaAutoRule::CONTENT);
        if ($stopped) {
            $rule->is_active = false;
        }
        DB::transaction(function () use ($rule, $before, $stopped) {
            $rule->save();
            AuditLog::log(AuditAction::WaAutoRuleUpdate, 'wa_auto_rule', $rule->id, $rule->name, ['before' => $before, 'after' => self::snapshot($rule), 'stopped' => $stopped]);
        });

        return back()->with('success', $stopped ? "حُفظت «{$rule->name}» وأُوقفت حتى تُجرَّب من جديد." : "حُفظت القاعدة «{$rule->name}».");
    }

    public function destroy(WaAutoRule $rule)
    {
        $this->authorizeRules();
        DB::transaction(function () use ($rule) {
            // النسخة الكاملة في سجل التدقيق: تُستعاد منه عند الحاجة
            AuditLog::log(AuditAction::WaAutoRuleDelete, 'wa_auto_rule', $rule->id, $rule->name, self::snapshot($rule));
            $rule->delete();
        });

        return back()->with('success', 'حُذفت القاعدة.');
    }

    /** التفعيل بعد معاينة أو محاكاة لاحقة لآخر تعديل، وقاعدة سليمة ونصها يجتاز الحارس الآن */
    public function toggle(Request $request, WaAutoRule $rule)
    {
        $this->authorizeRules();
        $on = $request->has('on') ? $request->boolean('on') : !$rule->is_active;
        if ($on && !$rule->is_active) {
            if ($rule->validated_at === null) {
                throw ValidationException::withMessages(['toggle' => 'جرّب القاعدة أو شغّل المحاكاة بعد آخر تعديل قبل تفعيلها.']);
            }
            $problems = RuleTextGuard::errors((string) $rule->reply_text_ar);
            if ($rule->reply_text_en) {
                array_push($problems, ...RuleTextGuard::errors((string) $rule->reply_text_en));
            }
            try {
                AutoRuleMatcher::assertValid($rule);
            } catch (\InvalidArgumentException $e) {
                $problems[] = $e->getMessage();
            }
            if ($problems) {
                throw ValidationException::withMessages(['toggle' => 'لا تُفعَّل: '.implode(' ', array_unique($problems))]);
            }
        }
        if ($on !== $rule->is_active) {
            DB::transaction(function () use ($rule, $on) {
                // بلا حدث الحفظ: التفعيل ليس تعديلاً للمحتوى
                WaAutoRule::whereKey($rule->id)->update(['is_active' => $on, 'updated_by' => auth()->id()]);
                AuditLog::log(AuditAction::WaAutoRuleToggle, 'wa_auto_rule', $rule->id, $rule->name, ['on' => $on]);
            });
        }

        return back()->with('success', $on ? "فُعّلت «{$rule->name}»." : "أُوقفت «{$rule->name}».");
    }

    public function reorder(Request $request)
    {
        $this->authorizeRules();
        $ids = $request->validate(['ids' => 'required|array|max:'.WaAutoRule::MAX_RULES, 'ids.*' => 'integer|distinct'])['ids'];

        DB::transaction(function () use ($ids) {
            foreach (array_values($ids) as $i => $id) {
                WaAutoRule::whereKey($id)->toBase()->update(['priority' => $i + 1]);
            }
            AuditLog::log(AuditAction::WaAutoRuleReorder, 'wa_auto_rule', null, null, ['order' => array_map('intval', array_values($ids))]);
        });

        return back()->with('success', 'حُفظ الترتيب.');
    }

    /**
     * «جرّب نصاً»: كل القواعد (المفعّلة والموقوفة) بترتيبها على نص المدير، بلا أي إرسال. يعيد القاعدة الأولى المطابقة ونصيها
     * معبّأين، وحكم كل قاعدة. يختم validated_at للمطابقة وحدها.
     */
    public function test(Request $request): JsonResponse
    {
        $this->authorizeRules();
        $data = $request->validate([
            'text' => 'required|string|max:500',
            'linked' => ['nullable', Rule::in(['yes', 'no'])],
            'topics' => 'nullable|array|max:10',
            'topics.*' => [Rule::in(array_keys(TopicRouter::TOPICS))],
        ]);
        $ctx = new AutoRuleContext(AutoRuleMatcher::normalize($data['text']), ['text'], ($data['linked'] ?? null) === 'yes', $data['topics'] ?? []);
        $rules = WaAutoRule::ordered()->get();

        $results = [];
        $match = null;
        foreach ($rules as $rule) {
            $reason = AutoRuleMatcher::evaluate($rule, $ctx);
            $results[] = ['id' => $rule->id, 'name' => $rule->name, 'is_active' => $rule->is_active, 'reason' => $reason];
            if ($reason === 'match' && $match === null) {
                $match = $rule;
            }
        }
        if ($match) {
            WaAutoRule::whereKey($match->id)->toBase()->update(['validated_at' => now()]);
        }

        return response()->json([
            'normalized' => $ctx->norm,
            'words' => $ctx->words,
            'match' => $match ? [
                'id' => $match->id, 'name' => $match->name, 'is_active' => $match->is_active,
                'reply_ar' => RuleTextGuard::render($match, false),
                'reply_en' => RuleTextGuard::render($match, true),
            ] : null,
            'results' => $results,
        ]);
    }

    /**
     * محاكاة على السجل: آخر 2000 رسالة نصية واردة من العملاء خلال 30 يوماً (لا محادثات الإدارة)، بسياق محادثتها. لكل قاعدة
     * عدد ما طابقته، ومنه ما كان لصاحبه طلب تسعير مفتوح، وما تجاوز حد الكلمات. لا نصوص عملاء إلا 3 مقتطفات (80 حرفاً)
     * لصاحب whatsapp.view_all. تختم validated_at لكل قاعدة سليمة شملتها.
     */
    public function simulate(): JsonResponse
    {
        $this->authorizeRules();
        $messages = WaMessage::where('direction', 'in')->where('source', 'customer')->where('msg_type', 'text')
            ->where('created_at', '>=', now()->subDays(self::SIMULATE_DAYS))
            ->orderByDesc('id')->limit(self::SIMULATE_LIMIT)->get(['id', 'conversation_id', 'body', 'referral', 'created_at']);
        $convIds = $messages->pluck('conversation_id')->unique()->values();
        $convs = WaConversation::whereIn('id', $convIds)->get()->keyBy('id')
            ->reject(fn (WaConversation $c) => $c->is_admin_chat || ($c->hasPhone() && WaAdminNumber::forPhone($c->phone)));
        $topics = DB::table('wa_conversation_topics')->whereIn('conversation_id', $convIds)->get(['conversation_id', 'topic'])
            ->groupBy('conversation_id')->map(fn ($rows) => $rows->pluck('topic')->all());
        $quotes = QuoteRequest::whereIn('conversation_id', $convIds)->whereIn('status', ['new', 'priced', 'sent'])
            ->get(['conversation_id', 'created_at'])->groupBy('conversation_id');
        $snippets = auth()->user()->can('whatsapp.view_all');

        $rules = WaAutoRule::ordered()->get();
        $stats = [];
        $valid = [];
        foreach ($rules as $rule) {
            $stats[$rule->id] = ['id' => $rule->id, 'name' => $rule->name, 'is_active' => $rule->is_active, 'matches' => 0, 'quote_open' => 0, 'too_long' => 0, 'invalid' => false]
                + ($snippets ? ['snippets' => []] : []);
            try {
                AutoRuleMatcher::assertValid($rule);
                $valid[] = $rule;
            } catch (\InvalidArgumentException) {
                AutoRuleMatcher::evaluate($rule, new AutoRuleContext(''));
                $stats[$rule->id]['invalid'] = true;
            }
        }

        $total = 0;
        foreach ($messages as $m) {
            $conv = $convs->get($m->conversation_id);
            if (!$conv) {
                continue;
            }
            $total++;
            $ctx = new AutoRuleContext(AutoRuleMatcher::normalize((string) $m->body), ['text'], (bool) $conv->financialClientId(),
                $topics->get($conv->id, []), is_array($m->referral) && $m->referral ? [$m->referral] : [], (bool) $conv->user_id);
            foreach ($valid as $rule) {
                $reason = AutoRuleMatcher::evaluate($rule, $ctx);
                if ($reason === 'too_long') {
                    $stats[$rule->id]['too_long']++;
                } elseif ($reason === 'match') {
                    $stats[$rule->id]['matches']++;
                    $open = $quotes->get($conv->id, collect())->contains(fn ($q) => $q->created_at <= $m->created_at && $q->created_at >= $m->created_at->copy()->subDay());
                    $stats[$rule->id]['quote_open'] += $open ? 1 : 0;
                    if ($snippets && count($stats[$rule->id]['snippets']) < 3) {
                        $stats[$rule->id]['snippets'][] = mb_substr((string) $m->body, 0, 80);
                    }
                }
            }
        }
        if ($valid) {
            WaAutoRule::whereIn('id', array_map(fn ($r) => $r->id, $valid))->toBase()->update(['validated_at' => now()]);
        }

        return response()->json([
            'total' => $total,
            'days' => self::SIMULATE_DAYS,
            'rules' => array_values(array_map(fn ($s) => $s + [
                'share' => $total ? round($s['matches'] / $total, 4) : 0,
                'broad' => $total > 0 && $s['matches'] / $total > self::BROAD_SHARE,
            ], $stats)),
        ]);
    }

    // ==================== المكوّنات الرسمية في واتساب (T6) ====================

    /** «قراءة الحالي من واتساب»: ما تعرضه ميتا الآن مقابل المحفوظ، ويُحفظ آخر قراءة (بلا رفع نسخة الإعدادات) */
    public function automation(): JsonResponse
    {
        $this->authorizeComponents();
        $res = WhatsAppChannel::getConversationalAutomation();
        $stored = ConversationalComponents::stored();
        if ($res['ok']) {
            SettingsWriter::apply(['conversational_automation' => ['last_snapshot' => $res['data'] + ['read_at' => now()->toIso8601String()]] + $stored],
                null, null, bumpVersion: false, audit: 'none');
        }

        return response()->json([
            'ok' => $res['ok'],
            'error' => $res['ok'] ? null : ($res['message'] ?? $res['code']),
            'live' => $res['data'] ?? null,
            'state' => ConversationalComponents::state(),
            'same' => $res['ok'] ? self::sameComponents($stored['desired'], $res['data']) : null,
        ], $res['ok'] ? 200 : 409);
    }

    /** المزامنة: التحقق قبل أي نداء، ثم نداء واحد لميتا، ثم الحفظ والتدقيق بالفرق. القائمتان الفارغتان = «مسح من واتساب» */
    public function syncAutomation(Request $request): JsonResponse
    {
        $this->authorizeComponents();
        $desired = ConversationalComponents::clean($request->validate(ConversationalComponents::rules(), ConversationalComponents::messages()));
        $res = WhatsAppChannel::setConversationalAutomation($desired);
        $stored = ConversationalComponents::stored();
        $after = $res['ok']
            ? ['desired' => $desired, 'synced_at' => now()->toIso8601String(), 'last_error' => null] + $stored
            : ['last_error' => array_filter(['code' => $res['code'], 'meta_code' => $res['meta_code'] ?? null, 'category' => $res['category'] ?? null,
                'fbtrace_id' => $res['fbtrace_id'] ?? null, 'at' => now()->toIso8601String()], fn ($v) => $v !== null)] + $stored;
        SettingsWriter::apply(['conversational_automation' => $after], null, AuditAction::WaConvAutomationSync, [
            'ok' => $res['ok'], 'prompts' => count($desired['prompts']), 'commands' => count($desired['commands']),
            'cleared' => $desired['prompts'] === [] && $desired['commands'] === [],
        ], label: 'المكوّنات الرسمية');

        return response()->json([
            'ok' => $res['ok'],
            'error' => $res['ok'] ? null : ($res['message'] ?? $res['code']),
            'warnings' => $res['ok'] ? ConversationalComponents::warnings($desired) : [],
            'state' => ConversationalComponents::state(),
        ], $res['ok'] ? 200 : 409);
    }

    // ==================== مساعدات ====================

    /** للصفحة: القاعدة وإحصاؤها وحكم الحارس على نصها الآن (قاعدة قديمة قبل تشديده) */
    private static function present(WaAutoRule $r, $names): array
    {
        $errors = RuleTextGuard::errors((string) $r->reply_text_ar);
        if ($r->reply_text_en) {
            array_push($errors, ...RuleTextGuard::errors((string) $r->reply_text_en));
        }

        return $r->only(['id', 'name', 'is_active', 'priority', 'match_type', 'patterns', 'conditions', 'max_words', 'reply_text_ar', 'reply_text_en',
            'reply_kind', 'tag_topic', 'cooldown_minutes', 'command_name', 'hits', 'shadow_hits', 'last_error']) + [
                'validated' => $r->validated_at !== null,
                'validated_at' => $r->validated_at?->format('Y-m-d H:i'),
                'last_hit_at' => $r->last_hit_at?->format('Y-m-d H:i'),
                'updated_by' => $r->updated_by ? ($names[$r->updated_by] ?? null) : null,
                'updated_at' => $r->updated_at?->format('Y-m-d H:i'),
                'guard_errors' => array_values(array_unique($errors)),
            ];
    }

    /** ما يُدقَّق: القاعدة كاملة (نصوص المالك لا نصوص عملاء) */
    private static function snapshot(WaAutoRule $r): array
    {
        return $r->only(['name', 'is_active', 'priority', 'match_type', 'patterns', 'conditions', 'max_words', 'reply_text_ar', 'reply_text_en',
            'reply_kind', 'tag_topic', 'cooldown_minutes', 'command_name']);
    }

    private static function sameComponents(array $desired, array $live): bool
    {
        $key = fn (array $c) => array_map(fn ($x) => [(string) data_get($x, 'command_name'), (string) data_get($x, 'command_description')], $c);

        return array_values($desired['prompts']) === array_values($live['prompts']) && $key($desired['commands']) === $key($live['commands']);
    }

    private function authorizeRules(): void
    {
        abort_unless(auth()->user()->can('whatsapp.auto_rules'), 403);
    }

    private function authorizeComponents(): void
    {
        abort_unless(auth()->user()->can('whatsapp.settings'), 403);
        abort_unless(FeatureFlags::on('conversational_components'), 404);
    }
}
