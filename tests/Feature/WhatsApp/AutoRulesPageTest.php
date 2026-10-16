<?php

namespace Tests\Feature\WhatsApp;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\QuoteRequest;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Models\WaAutoRule;
use App\Models\WaConversation;
use App\Models\WaMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * صفحة الردود الحتمية (E37): الصلاحية على كل مسار، والحد 32، ولا تفعيل بلا معاينة بعد آخر تعديل، والحارس على النصين،
 * والتدقيق لكل كتابة، و«جرّب نصاً» والمحاكاة بلا نصوص عملاء إلا لصاحب رؤية كل المحادثات.
 */
class AutoRulesPageTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = User::factory()->withPermissions(['whatsapp.auto_rules'], 'rules-manager')->create();
        Setting::set('company_address', 'عمّان');
    }

    private function payload(array $over = []): array
    {
        return $over + [
            'name' => 'الموقع', 'match_type' => 'any_of', 'patterns' => ['موقعكم', 'العنوان'], 'conditions' => null,
            'max_words' => 6, 'cooldown_minutes' => 360, 'reply_text_ar' => 'مكتبنا في {company.address}.', 'reply_text_en' => null,
            'tag_topic' => null, 'command_name' => null,
        ];
    }

    private function rule(array $attrs = []): WaAutoRule
    {
        return WaAutoRule::create($attrs + ['name' => 'الموقع', 'match_type' => 'any_of', 'patterns' => ['موقعكم'],
            'reply_text_ar' => 'مكتبنا في {company.address}.', 'priority' => 1]);
    }

    public static function routes(): array
    {
        return [
            'page' => ['get', '/whatsapp/auto-rules'],
            'store' => ['post', '/whatsapp/auto-rules'],
            'update' => ['put', '/whatsapp/auto-rules/{id}'],
            'destroy' => ['delete', '/whatsapp/auto-rules/{id}'],
            'toggle' => ['post', '/whatsapp/auto-rules/{id}/toggle'],
            'reorder' => ['post', '/whatsapp/auto-rules/reorder'],
            'test' => ['post', '/api/whatsapp/auto-rules/test'],
            'simulate' => ['post', '/api/whatsapp/auto-rules/simulate'],
        ];
    }

    #[DataProvider('routes')]
    public function test_every_route_needs_the_permission(string $method, string $uri): void
    {
        $rule = $this->rule();
        $outsider = User::factory()->withPermissions(['whatsapp.view', 'whatsapp.reply'], 'outsider')->create();

        $this->actingAs($outsider)->json($method, str_replace('{id}', (string) $rule->id, $uri), $this->payload() + ['text' => 'x', 'ids' => [$rule->id]])
            ->assertForbidden();
        $this->assertSame(1, WaAutoRule::count());
        $this->assertSame(0, AuditLog::where('action', 'like', 'wa.auto_rule%')->count());
    }

    public function test_the_page_lists_rules_limits_variables_and_mode(): void
    {
        $this->rule()->forceFill(['hits' => 4])->saveQuietly();

        $this->actingAs($this->manager)->get('/whatsapp/auto-rules')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('WhatsApp/AutoRules', false)
                ->has('rules', 1)
                ->where('rules.0.hits', 4)
                ->where('rules.0.validated', false)
                ->where('limits.rules', 32)
                ->where('mode', 'shadow')
                ->where('flag.on', false)
                ->has('variables', 6)
                ->where('automation', null));
    }

    public function test_a_new_rule_is_saved_off_and_audited(): void
    {
        $this->actingAs($this->manager)->post('/whatsapp/auto-rules', $this->payload(['patterns' => [' /الموقع ', 'العنوان']]))->assertRedirect();

        $rule = WaAutoRule::sole();
        $this->assertFalse($rule->is_active);
        $this->assertNull($rule->validated_at);
        $this->assertSame(['/الموقع', 'العنوان'], $rule->patterns);
        $this->assertSame(['الموقع', 'العنوان'], $rule->patterns_norm);
        $audit = AuditLog::where('action', AuditAction::WaAutoRuleCreate->value)->sole();
        $this->assertSame($rule->id, $audit->entity_id);
        $this->assertSame('مكتبنا في {company.address}.', $audit->changes['reply_text_ar']);
    }

    public function test_the_thirty_third_rule_is_refused(): void
    {
        foreach (range(1, 32) as $i) {
            $this->rule(['name' => "قاعدة {$i}"]);
        }

        $this->actingAs($this->manager)->post('/whatsapp/auto-rules', $this->payload())->assertSessionHasErrors('name');

        $this->assertSame(32, WaAutoRule::count());
    }

    public static function badTexts(): array
    {
        return [
            'price' => ['reply_text_ar', 'السعر 350 دينار'],
            'payment' => ['reply_text_ar', 'حوّل على كليك nusk'],
            'raw phone' => ['reply_text_ar', 'اتصل 0790000000'],
            'internal link' => ['reply_text_en', 'See https://example.com/storage/a.png'],
            'unknown variable' => ['reply_text_ar', 'أهلاً {name}'],
        ];
    }

    #[DataProvider('badTexts')]
    public function test_the_guard_refuses_unsafe_text(string $field, string $text): void
    {
        $this->actingAs($this->manager)->post('/whatsapp/auto-rules', $this->payload([$field => $text]))->assertSessionHasErrors($field);

        $this->assertSame(0, WaAutoRule::count());
    }

    public function test_patterns_and_conditions_are_validated(): void
    {
        $this->actingAs($this->manager)->post('/whatsapp/auto-rules', $this->payload(['patterns' => ['؟؟']]))->assertSessionHasErrors('patterns.0');
        $this->actingAs($this->manager)->post('/whatsapp/auto-rules', $this->payload(['patterns' => array_map(fn ($i) => "قيمة{$i}", range(1, 11))]))->assertSessionHasErrors('patterns');
        $this->actingAs($this->manager)->post('/whatsapp/auto-rules', $this->payload(['conditions' => ['from' => '962']]))->assertSessionHasErrors('conditions');
        $this->actingAs($this->manager)->post('/whatsapp/auto-rules', $this->payload(['match_type' => 'regex']))->assertSessionHasErrors('match_type');
        $this->actingAs($this->manager)->post('/whatsapp/auto-rules', $this->payload(['reply_kind' => 'location']))->assertSessionHasErrors('reply_kind');

        $this->assertSame(0, WaAutoRule::count());
    }

    public function test_activation_needs_a_preview_after_the_last_edit(): void
    {
        $this->actingAs($this->manager)->post('/whatsapp/auto-rules', $this->payload());
        $rule = WaAutoRule::sole();

        $this->actingAs($this->manager)->post("/whatsapp/auto-rules/{$rule->id}/toggle", ['on' => true])->assertSessionHasErrors('toggle');
        $this->assertFalse($rule->fresh()->is_active);

        $this->actingAs($this->manager)->postJson('/api/whatsapp/auto-rules/test', ['text' => 'وين موقعكم؟'])->assertOk()->assertJsonPath('match.id', $rule->id);
        $this->actingAs($this->manager)->post("/whatsapp/auto-rules/{$rule->id}/toggle", ['on' => true])->assertSessionHasNoErrors();
        $this->assertTrue($rule->fresh()->is_active);

        // تعديل المحتوى يوقفها ويعيد الرفض حتى تُجرَّب من جديد
        $this->actingAs($this->manager)->put("/whatsapp/auto-rules/{$rule->id}", $this->payload(['patterns' => ['الموقع']]))->assertSessionHas('success');
        $this->assertFalse($rule->fresh()->is_active);
        $this->assertNull($rule->fresh()->validated_at);
        $this->actingAs($this->manager)->post("/whatsapp/auto-rules/{$rule->id}/toggle", ['on' => true])->assertSessionHasErrors('toggle');

        // تعديل الاسم وحده ليس محتوى
        $this->actingAs($this->manager)->postJson('/api/whatsapp/auto-rules/test', ['text' => 'الموقع']);
        $this->actingAs($this->manager)->put("/whatsapp/auto-rules/{$rule->id}", $this->payload(['patterns' => ['الموقع'], 'name' => 'المكتب']));
        $this->assertNotNull($rule->fresh()->validated_at);
    }

    public function test_every_write_is_audited_and_delete_keeps_the_full_copy(): void
    {
        $this->actingAs($this->manager)->post('/whatsapp/auto-rules', $this->payload());
        $a = WaAutoRule::sole();
        $this->actingAs($this->manager)->post('/whatsapp/auto-rules', $this->payload(['name' => 'الدوام', 'patterns' => ['الدوام']]));
        $b = WaAutoRule::where('name', 'الدوام')->sole();
        $this->actingAs($this->manager)->put("/whatsapp/auto-rules/{$a->id}", $this->payload(['cooldown_minutes' => 120]));
        $a->forceFill(['validated_at' => now()])->saveQuietly();
        $this->actingAs($this->manager)->post("/whatsapp/auto-rules/{$a->id}/toggle", ['on' => true]);
        $this->actingAs($this->manager)->post('/whatsapp/auto-rules/reorder', ['ids' => [$b->id, $a->id]]);
        $this->actingAs($this->manager)->delete("/whatsapp/auto-rules/{$a->id}");

        $actions = AuditLog::orderBy('id')->pluck('action')->all();
        $this->assertSame(['wa.auto_rule_create', 'wa.auto_rule_create', 'wa.auto_rule_update', 'wa.auto_rule_toggle', 'wa.auto_rule_reorder', 'wa.auto_rule_delete'], $actions);
        $deleted = AuditLog::where('action', 'wa.auto_rule_delete')->sole();
        $this->assertSame(['name', 'is_active', 'priority', 'match_type', 'patterns', 'conditions', 'max_words', 'reply_text_ar', 'reply_text_en',
            'reply_kind', 'tag_topic', 'cooldown_minutes', 'command_name'], array_keys($deleted->changes));
        $this->assertSame(['موقعكم', 'العنوان'], $deleted->changes['patterns']);
        $this->assertNull($a->fresh());
        $this->assertSame(1, $b->fresh()->priority);
        $this->assertSame(120, AuditLog::where('action', 'wa.auto_rule_update')->sole()->changes['after']['cooldown_minutes']);
    }

    public function test_the_test_box_shows_the_match_and_its_filled_replies_without_sending(): void
    {
        $other = $this->rule(['name' => 'الدوام', 'patterns' => ['الدوام'], 'priority' => 1]);
        $rule = $this->rule(['name' => 'الموقع', 'patterns' => ['موقعكم'], 'priority' => 2, 'reply_text_en' => 'Office: {company.address}', 'conditions' => ['linked' => 'yes']]);

        $no = $this->actingAs($this->manager)->postJson('/api/whatsapp/auto-rules/test', ['text' => 'وين موقعكم'])->assertOk();
        $this->assertNull($no->json('match'));
        $this->assertSame(['no_match', 'conditions'], array_column($no->json('results'), 'reason'));

        $yes = $this->actingAs($this->manager)->postJson('/api/whatsapp/auto-rules/test', ['text' => 'وين موقعكم', 'linked' => 'yes'])->assertOk();
        $this->assertSame(['id' => $rule->id, 'name' => 'الموقع', 'is_active' => false, 'reply_ar' => 'مكتبنا في عمّان.', 'reply_en' => 'Office: عمّان'], $yes->json('match'));
        $this->assertSame('وين موقعكم', $yes->json('normalized'));
        $this->assertNotNull($rule->fresh()->validated_at);
        $this->assertNull($other->fresh()->validated_at, 'تختم المطابقة وحدها');
        $this->assertSame(0, WaMessage::count());
    }

    public function test_simulation_counts_matches_without_customer_text_unless_allowed(): void
    {
        $broad = $this->rule(['name' => 'واسعة', 'patterns' => ['عمره', 'العمره'], 'priority' => 1, 'max_words' => 6]);
        $narrow = $this->rule(['name' => 'ضيقة', 'patterns' => ['موقعكم'], 'priority' => 2]);
        $conv = WaConversation::factory()->create();
        foreach (['كم سعر العمرة', 'العمرة متى', 'بدي عمرة', 'وين موقعكم', 'مرحبا', 'شكرا', 'تمام'] as $text) {
            WaMessage::factory()->inbound()->create(['conversation_id' => $conv->id, 'body' => $text]);
        }
        WaMessage::factory()->inbound()->create(['conversation_id' => $conv->id, 'body' => 'مرحبا اريد ان اسال عن العمرة في شهر رمضان القادم لو سمحت']);
        QuoteRequest::factory()->create(['conversation_id' => $conv->id, 'status' => 'new', 'created_at' => now()->subHours(2)]);
        // الصادر والقديم خارج المحاكاة
        WaMessage::factory()->outbound()->create(['conversation_id' => $conv->id, 'body' => 'العمرة متاحة']);
        WaMessage::factory()->inbound()->create(['conversation_id' => $conv->id, 'body' => 'عمرة', 'created_at' => now()->subDays(40)]);

        $res = $this->actingAs($this->manager)->postJson('/api/whatsapp/auto-rules/simulate')->assertOk();

        $this->assertSame(8, $res->json('total'));
        $rows = collect($res->json('rules'))->keyBy('id');
        $this->assertSame([3, 3, 1, true], [$rows[$broad->id]['matches'], $rows[$broad->id]['quote_open'], $rows[$broad->id]['too_long'], $rows[$broad->id]['broad']]);
        $this->assertSame([1, false], [$rows[$narrow->id]['matches'], $rows[$narrow->id]['broad']]);
        $this->assertArrayNotHasKey('snippets', $rows[$broad->id]);
        $this->assertStringNotContainsString('سعر العمرة', json_encode($res->json(), JSON_UNESCAPED_UNICODE));
        $this->assertNotNull($broad->fresh()->validated_at);
        $this->assertNotNull($narrow->fresh()->validated_at);

        $viewer = User::factory()->withPermissions(['whatsapp.auto_rules', 'whatsapp.view_all'], 'rules-viewer')->create();
        WaMessage::factory()->inbound()->create(['conversation_id' => $conv->id, 'body' => 'عمره '.str_repeat('طويلة ', 3)]);
        WaMessage::factory()->inbound()->create(['conversation_id' => $conv->id, 'body' => 'عمرة؟']);
        $rows = collect($this->actingAs($viewer)->postJson('/api/whatsapp/auto-rules/simulate')->assertOk()->json('rules'))->keyBy('id');
        $this->assertCount(3, $rows[$broad->id]['snippets']);
        foreach ($rows[$broad->id]['snippets'] as $s) {
            $this->assertLessThanOrEqual(80, mb_strlen($s));
        }
    }

    public function test_admin_number_chats_stay_out_of_the_simulation(): void
    {
        $this->rule(['patterns' => ['تقرير']]);
        $admin = WaConversation::factory()->create(['is_admin_chat' => true]);
        DB::table('wa_conversations')->where('id', $admin->id)->update(['is_admin_chat' => true]);
        WaMessage::factory()->inbound()->create(['conversation_id' => $admin->id, 'body' => 'تقرير اليوم']);

        $res = $this->actingAs($this->manager)->postJson('/api/whatsapp/auto-rules/simulate')->assertOk();

        $this->assertSame(0, $res->json('total'));
    }

    public function test_the_migration_grants_the_permission_to_the_admin_role(): void
    {
        $admin = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'مدير']);
        $migration = require database_path('migrations/2026_10_14_000037_wa_auto_rules.php');

        $migration->up();
        $migration->up();

        $permission = DB::table('permissions')->where('slug', 'whatsapp.auto_rules')->value('id');
        $this->assertSame(1, DB::table('role_permissions')->where(['role_id' => $admin->id, 'permission_id' => $permission])->count());
        $this->assertSame(1, DB::table('permissions')->where('slug', 'whatsapp.auto_rules')->count());
    }
}
