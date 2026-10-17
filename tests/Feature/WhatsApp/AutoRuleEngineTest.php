<?php

namespace Tests\Feature\WhatsApp;

use App\Models\QuoteRequest;
use App\Models\Setting;
use App\Models\User;
use App\Models\WaAdminNumber;
use App\Models\WaAutoRule;
use App\Models\WaBotSetting;
use App\Models\WaBotUsage;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\AutoRuleEngine;
use App\Services\WhatsApp\BotAnalytics;
use App\Services\WhatsApp\BotEngine;
use App\Services\WhatsApp\HandoffGuard;
use App\Services\WhatsApp\LlmCircuitBreaker;
use App\Services\WhatsApp\ReplyRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CapturesLogs;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * الردود الحتمية داخل BotEngine::reply (E37): بعد كل البوابات وقبل النموذج، بالتهدئة واللغة ووضع الظل، وعبر
 * WhatsAppChannel بالمصدر rule. وفي كل حالة لا تعمل فيها القاعدة يبقى المسار كما هو اليوم.
 */
class AutoRuleEngineTest extends TestCase
{
    use CapturesLogs;
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    private const MODEL_REPLY = 'رد من النموذج';

    private const ADDRESS = 'عمّان، الشميساني';

    private WaConversation $conv;

    private int $modelCalls = 0;

    /** Http::fake يراكم الأنماط والأول يغلب: رد ميتا من خاصية يبدّلها الاختبار */
    private int $graphStatus = 200;

    protected function setUp(): void
    {
        parent::setUp();
        config(['features.env.auto_rules' => true]);
        $this->configureChannel(['auto_rules_mode' => 'live']);
        Setting::set('company_address', self::ADDRESS);
        $this->conv = WaConversation::factory()->windowOpen()->create();
        $this->modelCalls = 0;
        $n = 0;
        Http::fake(['graph.facebook.com/*' => function () use (&$n) {
            $n++;

            return $this->graphStatus === 200
                ? Http::response(['messaging_product' => 'whatsapp', 'messages' => [['id' => 'wamid.OUT.'.$n]]])
                : Http::response(['error' => ['code' => 1, 'message' => 'x']], $this->graphStatus);
        }]);
        Http::fake(['generativelanguage.googleapis.com/*' => function () {
            $this->modelCalls++;

            return Http::response($this->geminiResponse(self::MODEL_REPLY));
        }]);
    }

    protected function tearDown(): void
    {
        ReplyRun::clear();
        AutoRuleEngine::flush();
        parent::tearDown();
    }

    private function rule(array $attrs = []): WaAutoRule
    {
        $rule = WaAutoRule::create($attrs + [
            'name' => 'الموقع', 'is_active' => true, 'match_type' => 'any_of', 'patterns' => ['موقعكم', 'الموقع', 'العنوان'],
            'reply_text_ar' => 'مكتبنا في {company.address}.', 'reply_text_en' => null, 'max_words' => 6, 'cooldown_minutes' => 360,
        ]);
        $rule->forceFill(['validated_at' => now()])->saveQuietly();

        return $rule->fresh();
    }

    private function inbound(string $body): WaMessage
    {
        $this->conv->update(['last_inbound_at' => now()]);

        return WaMessage::factory()->inbound()->create(['conversation_id' => $this->conv->id, 'body' => $body]);
    }

    /** @return list<string> */
    private function sent(string $source): array
    {
        return WaMessage::where('conversation_id', $this->conv->id)->where('direction', 'out')->where('source', $source)->orderBy('id')->pluck('body')->all();
    }

    public function test_a_live_rule_answers_without_the_model(): void
    {
        $rule = $this->rule();
        $in = $this->inbound('وين موقعكم؟');

        $result = BotEngine::reply($this->conv);

        $this->assertSame(['sent' => true, 'reason' => 'auto_rule'], $result);
        $this->assertSame(0, $this->modelCalls);
        $this->assertSame(['مكتبنا في '.self::ADDRESS.'.'], $this->sent('rule'));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/messages') && ($r['text']['body'] ?? null) === 'مكتبنا في '.self::ADDRESS.'.');
        $this->assertSame([], $this->sent('bot'));

        $usage = WaBotUsage::where('conversation_id', $this->conv->id)->sole();
        $this->assertSame(['live', 'rule', 0, ['auto_rule:'.$rule->id]], [$usage->source, $usage->model, $usage->calls, $usage->tools_used]);
        $this->assertSame($in->id, $this->conv->fresh()->bot_answered_in_id);
        $this->assertSame(1, (int) Cache::get('wa:rate:'.$this->conv->id.':'.now()->format('YmdH')));
        $this->assertSame(1, $rule->fresh()->hits);
        $this->assertNotNull($rule->fresh()->last_hit_at);
    }

    public function test_the_rule_reply_carries_its_own_send_key(): void
    {
        config(['features.env.send_ledger' => true]);
        $this->rule();
        $in = $this->inbound('العنوان');

        BotEngine::reply($this->conv);

        $this->assertSame('rule:'.$this->conv->id.':'.$in->id, WaMessage::where('source', 'rule')->sole()->send_key);
    }

    public static function closedCases(): array
    {
        return [
            'bot disabled' => ['disabled', false],
            'paused after staff' => ['paused', false],
            'window closed' => ['window', false],
            'flag off' => ['flag_off', true],
            'open quote request' => ['quote', true],
            'long message' => ['long', true],
            'rate limited' => ['rate', false],
            'admin number' => ['admin', true],
            'staff number' => ['staff', true],
        ];
    }

    #[DataProvider('closedCases')]
    public function test_no_rule_reply_and_the_usual_path(string $case, bool $modelAnswers): void
    {
        $this->rule();
        $this->inbound($case === 'long' ? 'مرحبا ممكن تحكيلي وين موقعكم بالضبط لأني جاي بكرا' : 'وين موقعكم');
        match ($case) {
            'disabled' => $this->conv->update(['bot_enabled' => false]),
            'paused' => $this->conv->update(['bot_paused_until' => now()->addMinutes(20)]),
            'window' => $this->conv->update(['last_inbound_at' => now()->subDays(2)]),
            'flag_off' => config(['features.env.auto_rules' => false]),
            'quote' => QuoteRequest::factory()->create(['conversation_id' => $this->conv->id, 'status' => 'priced', 'created_at' => now()->subHour()]),
            'long' => null,
            'rate' => Cache::put('wa:rate:'.$this->conv->id.':'.now()->format('YmdH'), 50, now()->addHour()),
            'admin' => WaAdminNumber::factory()->create(['phone' => $this->conv->phone]),
            'staff' => $this->conv->update(['user_id' => User::factory()->create()->id]),
        };

        BotEngine::reply($this->conv);

        $this->assertSame([], $this->sent('rule'), $case);
        $this->assertSame($modelAnswers ? 1 : 0, $this->modelCalls, $case);
        if ($modelAnswers) {
            $this->assertSame([self::MODEL_REPLY], $this->sent('bot'), $case);
        }
        if ($case === 'rate') {
            $this->assertSame([HandoffGuard::confirmation(false)], $this->sent('bot'));
        }
    }

    public function test_an_old_quote_request_does_not_block_rules(): void
    {
        $this->rule();
        QuoteRequest::factory()->create(['conversation_id' => $this->conv->id, 'status' => 'priced', 'created_at' => now()->subDays(2)]);
        $this->inbound('وين موقعكم');

        BotEngine::reply($this->conv);

        $this->assertCount(1, $this->sent('rule'));
    }

    public function test_a_request_for_a_human_wins_over_any_rule(): void
    {
        $this->rule(['patterns' => ['موظف']]);
        $this->inbound('بدي احكي مع موظف');

        BotEngine::reply($this->conv);

        $this->assertSame([], $this->sent('rule'));
        $this->assertSame([HandoffGuard::confirmation(false)], $this->sent('bot'));
        $this->assertFalse($this->conv->fresh()->bot_enabled);
    }

    public function test_the_same_rule_waits_for_its_cooldown_per_conversation(): void
    {
        $rule = $this->rule(['cooldown_minutes' => 60]);
        $this->inbound('وين موقعكم');
        BotEngine::reply($this->conv);

        $this->inbound('وين موقعكم؟');
        BotEngine::reply($this->conv);
        $this->assertCount(1, $this->sent('rule'));
        $this->assertSame([self::MODEL_REPLY], $this->sent('bot'), 'أثناء التهدئة يجيب النموذج');

        $this->travel(61)->minutes();
        $this->inbound('العنوان');
        BotEngine::reply($this->conv);
        $this->assertCount(2, $this->sent('rule'));
        $this->assertSame(2, $rule->fresh()->hits);

        // محادثة أخرى لها تهدئتها
        $other = WaConversation::factory()->windowOpen()->create();
        WaMessage::factory()->inbound()->create(['conversation_id' => $other->id, 'body' => 'وين موقعكم']);
        BotEngine::reply($other);
        $this->assertSame(1, WaMessage::where('conversation_id', $other->id)->where('source', 'rule')->count());
    }

    public function test_the_cooldown_survives_a_restart_on_the_database_store(): void
    {
        config(['cache.default' => 'database']);
        $this->rule();
        $this->inbound('وين موقعكم');
        BotEngine::reply($this->conv);

        // «إعادة تشغيل»: لا شيء في ذاكرة العملية
        Cache::forgetDriver('database');
        app()->forgetInstance('cache.store');

        $this->inbound('وين موقعكم');
        BotEngine::reply($this->conv);

        $this->assertCount(1, $this->sent('rule'));
        $this->assertSame([self::MODEL_REPLY], $this->sent('bot'));
        $this->assertTrue(DB::table('cache')->where('key', 'like', '%wa:rule:cd:%')->exists());
    }

    public function test_english_needs_an_english_text(): void
    {
        $rule = $this->rule(['patterns' => ['office', 'موقعكم']]);
        $this->inbound('where is your office');
        BotEngine::reply($this->conv);
        $this->assertSame([], $this->sent('rule'));
        $this->assertSame([self::MODEL_REPLY], $this->sent('bot'));

        $rule->update(['reply_text_en' => 'Our office is at {company.address}.']);
        $this->inbound('where is your office');
        BotEngine::reply($this->conv);
        $this->assertSame(['Our office is at '.self::ADDRESS.'.'], $this->sent('rule'));
    }

    public function test_shadow_mode_counts_and_lets_the_model_answer(): void
    {
        WaBotSetting::current()->forceFill(['auto_rules_mode' => 'shadow'])->save();
        $rule = $this->rule();
        $before = BotAnalytics::usage()['tiles'];
        $this->inbound('وين موقعكم');

        BotEngine::reply($this->conv);

        $this->assertSame([], $this->sent('rule'));
        $this->assertSame([self::MODEL_REPLY], $this->sent('bot'));
        $this->assertSame(1, $rule->fresh()->shadow_hits);
        $this->assertSame(0, $rule->fresh()->hits);
        $shadow = WaBotUsage::where('source', 'shadow')->sole();
        $this->assertSame(['rule', 0, ['auto_rule:'.$rule->id]], [$shadow->model, $shadow->calls, $shadow->tools_used]);
        // التحليلات تقرأ live وحده: رد النموذج وحده يُعدّ، والظل لا يغيّر شيئاً
        $after = BotAnalytics::usage()['tiles'];
        $this->assertSame($before['replies'] + 1, $after['replies']);
        $this->assertSame(1, WaBotUsage::where('source', 'live')->count());
    }

    public function test_a_staff_takeover_during_the_run_drops_the_rule_reply(): void
    {
        $this->rule();
        $this->inbound('وين موقعكم');
        $run = ReplyRun::start($this->conv, 'tok');
        WaMessage::factory()->fromStaff(User::factory()->create())->create(['conversation_id' => $this->conv->id]);
        $mark = $this->conv->fresh()->bot_answered_in_id;

        $result = BotEngine::reply($this->conv, $run);

        $this->assertSame(['sent' => false, 'reason' => 'stale_takeover'], $result);
        $this->assertSame([], $this->sent('rule'));
        $this->assertSame(0, $this->modelCalls);
        $this->assertSame($mark, $this->conv->fresh()->bot_answered_in_id);
        $this->assertTrue(WaBotUsage::where('source', 'gate')->where('tools_used', json_encode(['stale_takeover']))->exists());
    }

    public function test_a_failed_send_does_not_move_the_watermark(): void
    {
        $this->graphStatus = 500;
        $this->rule();
        $this->inbound('وين موقعكم');
        $mark = $this->conv->fresh()->bot_answered_in_id;

        $result = BotEngine::reply($this->conv);

        $this->assertFalse($result['sent']);
        $this->assertSame($mark, $this->conv->fresh()->bot_answered_in_id);
        $this->assertSame(0, $this->modelCalls, 'لا رد ثانٍ من النموذج بعد محاولة القاعدة');
    }

    public function test_an_empty_company_variable_leaves_the_message_to_the_model(): void
    {
        $this->captureLogs();
        $rule = $this->rule(['reply_text_ar' => 'الدوام {company.hours}']);
        $this->inbound('وين موقعكم');
        BotEngine::reply($this->conv);
        $this->inbound('العنوان');
        BotEngine::reply($this->conv);

        $this->assertSame([], $this->sent('rule'));
        $this->assertSame([self::MODEL_REPLY, self::MODEL_REPLY], $this->sent('bot'));
        $this->assertSame(1, collect($this->logged)->where('message', 'auto_rule_variable_empty')->count());
        $this->assertLogged('error', 'auto_rule_variable_empty', ['rule' => $rule->id]);
    }

    public function test_tag_topic_tags_the_conversation_and_assigns_its_primary_quietly(): void
    {
        $specialist = User::factory()->create();
        DB::table('wa_topic_users')->insert(['topic' => 'visa', 'user_id' => $specialist->id, 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->rule(['patterns' => ['وثائق'], 'reply_text_ar' => 'جواز ساري 7 أشهر على الأقل.', 'tag_topic' => 'visa']);
        $this->inbound('شو وثائق التأشيرة');

        BotEngine::reply($this->conv);

        $this->assertDatabaseHas('wa_conversation_topics', ['conversation_id' => $this->conv->id, 'topic' => 'visa', 'source' => 'rule']);
        $this->assertSame($specialist->id, (int) $this->conv->fresh()->assigned_to);
        $this->assertFalse((bool) $this->conv->fresh()->needs_attention);
    }

    public function test_rules_keep_answering_during_a_provider_outage(): void
    {
        WaBotSetting::current()->forceFill(['breaker_mode' => 'on'])->save();
        LlmCircuitBreaker::forceOpen('google', 30, null);
        $this->rule();
        $this->inbound('وين موقعكم');

        BotEngine::reply($this->conv);

        $this->assertSame(['مكتبنا في '.self::ADDRESS.'.'], $this->sent('rule'));
        $this->assertSame(0, $this->modelCalls);
        $this->assertFalse((bool) $this->conv->fresh()->needs_attention);
    }

    public function test_the_content_policy_treats_rule_replies_like_bot_replies(): void
    {
        config(['features.env.outbound_policy_enforce' => true]);
        $this->rule(['reply_text_ar' => 'استعمل get_offers لمعرفة العروض']);
        $this->inbound('وين موقعكم');

        BotEngine::reply($this->conv);

        $row = WaMessage::where('source', 'rule')->sole();
        $this->assertSame('blocked', $row->status);
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/messages'));
    }

    public function test_the_engine_fails_closed(): void
    {
        $this->captureLogs();
        $this->rule();
        $this->inbound('وين موقعكم');
        // الجدول يختفي بعد أن عُرف وجوده (استعادة نسخة أقدم مثلاً): الاستعلام يفشل والمحرّك يكمل إلى النموذج
        $this->assertTrue(AutoRuleEngine::ready());
        Schema::drop('wa_auto_rules');

        BotEngine::reply($this->conv);

        $this->assertSame([self::MODEL_REPLY], $this->sent('bot'));
        $this->assertLogged('error', 'auto_rule_engine', ['conv_id' => $this->conv->id, 'rule' => null, 'type' => 'QueryException']);
    }

    public function test_the_model_sees_what_the_rule_said(): void
    {
        $this->rule();
        $this->inbound('وين موقعكم');
        BotEngine::reply($this->conv);

        $this->inbound('وكم تكلفة العمرة؟');
        BotEngine::reply($this->conv);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'generativelanguage')
            && str_contains(json_encode($r->data(), JSON_UNESCAPED_UNICODE), 'مكتبنا في '.self::ADDRESS));
    }

    public function test_an_inactive_rule_never_answers(): void
    {
        $this->rule(['is_active' => false]);
        $this->inbound('وين موقعكم');

        BotEngine::reply($this->conv);

        $this->assertSame([], $this->sent('rule'));
        $this->assertSame([self::MODEL_REPLY], $this->sent('bot'));
    }
}
