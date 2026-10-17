<?php

namespace Tests\Feature\WhatsApp\Tools;

use App\Models\User;
use App\Models\WaAdminNumber;
use App\Models\WaBotSetting;
use App\Models\WaConversation;
use App\Services\WhatsApp\AdminTools;
use App\Services\WhatsApp\BotEngine;
use App\Services\WhatsApp\BotTools;
use App\Services\WhatsApp\HandoffGuard;
use App\Services\WhatsApp\Settings\SettingsSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * سجل الأدوات الموحّد (E15.1/E15.2/E15.4): كل القوائم مشتقة من BotTools::TOOLS وAdminTools::TOOLS، واللقطات
 * تثبّت العقد (أي تغيير يتطلب تحديث tests/Snapshots عمداً)، وhandoff_to_human لا تُطفأ من أي مسار.
 */
class ToolRegistryTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    private const IDENTITY_FIELDS = ['client_id', 'phone', 'conversation_id', 'user_id', 'wa_id', 'wa_user_id'];

    private static function shape(array $decls): array
    {
        return array_map(fn ($d) => [
            'name' => $d['name'],
            'required' => $d['input_schema']['required'] ?? [],
            'properties' => array_keys($d['input_schema']['properties'] ?? []),
            'enums' => array_filter(array_map(fn ($p) => $p['enum'] ?? null, $d['input_schema']['properties'] ?? [])),
        ], $decls);
    }

    private static function snapshot(string $name): array
    {
        return json_decode(file_get_contents(base_path("tests/Snapshots/{$name}.json")), true);
    }

    private function allPermissionsNumber(): WaAdminNumber
    {
        return new WaAdminNumber(['permissions' => array_keys(AdminTools::CATALOG), 'is_active' => true]);
    }

    // ==================== اللقطات ====================

    public function test_bot_declarations_match_the_snapshot(): void
    {
        $this->assertSame(self::snapshot('bot_declarations'), self::shape(BotTools::declarations(new WaBotSetting(['tools_config' => []]), new WaConversation)));
    }

    public function test_admin_declarations_and_permissions_match_the_snapshots(): void
    {
        $decls = self::shape(AdminTools::declarations($this->allPermissionsNumber()));
        $this->assertSame(self::snapshot('admin_declarations'), $decls);

        $perms = [];
        foreach ($decls as $d) {
            foreach ($d['enums']['action'] ?? ['_'] as $a) {
                $act = $a === '_' ? '' : $a;
                $perms[$d['name'].':'.$a] = ['perm' => AdminTools::permissionFor($d['name'], $act), 'write' => AdminTools::isWrite($d['name'], $act)];
            }
        }
        $this->assertSame(self::snapshot('admin_permissions'), $perms);
    }

    // ==================== العقد ====================

    public function test_every_derived_list_comes_from_the_registry(): void
    {
        $names = BotTools::names();
        // ما شُحن مطفأً (E33: الدبوس والبطاقة) لا يُعلن حتى يُفعَّل
        $this->assertSame(array_values(array_filter($names, [BotTools::class, 'defaultEnabled'])), array_column(BotTools::declarations(new WaBotSetting(['tools_config' => []]), new WaConversation), 'name'));
        // E36: القوائم والأزرار ونموذج طلب التسعير مطفأة كذلك
        $this->assertSame(['send_office_location', 'send_contact_card', 'show_offers_menu', 'present_choices', 'send_quote_form'],
            array_values(array_filter($names, fn ($n) => !BotTools::defaultEnabled($n))));
        $this->assertSame($names, array_keys(BotTools::labels()));
        $this->assertSame([], array_diff(HandoffGuard::escalatingTools(), $names));
        $this->assertEqualsCanonicalizing(['handoff_to_human', 'request_quote', 'confirm_booking'], HandoffGuard::escalatingTools());
        $this->assertContains('array:'.implode(',', $names), SettingsSchema::fieldRules('tools_config'), 'مفاتيح tools_config المقبولة = أسماء الأدوات');

        foreach (BotTools::TOOLS as $name => $t) {
            $this->assertTrue(method_exists(BotTools::class, $t['handler']), "{$name}: معالج غير موجود");
        }
        foreach (AdminTools::TOOLS as $name => $t) {
            $this->assertTrue(method_exists(AdminTools::class, $t['handler']), "{$name}: معالج غير موجود");
            foreach ($t['actions'] as $action => $a) {
                foreach ((array) $a['perm'] as $perm) {
                    $this->assertArrayHasKey($perm, AdminTools::CATALOG, "{$name}:{$action}");
                }
            }
        }
        $this->assertSame(['manage_knowledge' => ['add', 'update', 'delete'], 'manage_bot_tools' => ['enable', 'disable'], 'set_customer_bot' => ['on', 'off'], 'block_contact' => ['block']], AdminTools::writes());
    }

    public function test_no_bot_tool_accepts_an_identity_field_and_schema_limits_match_the_rules(): void
    {
        foreach (BotTools::TOOLS as $name => $t) {
            $this->assertSame([], array_intersect(self::IDENTITY_FIELDS, array_keys($t['properties'])), "{$name}: الهوية من المحادثة وحدها");
            foreach ($t['properties'] as $field => $p) {
                $rule = (string) ($t['rules'][$field] ?? '');
                if (isset($p['maxLength'])) {
                    $this->assertStringContainsString('max:'.$p['maxLength'], $rule, "{$name}.{$field}: maxLength = max");
                }
                if (isset($p['maximum'])) {
                    $this->assertStringContainsString('max:'.$p['maximum'], $rule, "{$name}.{$field}: maximum = max");
                }
                if (isset($p['minimum'])) {
                    $this->assertStringContainsString('min:'.$p['minimum'], $rule, "{$name}.{$field}: minimum = min");
                }
            }
        }
    }

    /** كل فعل إداري له صلاحية من CATALOG، وكل صلاحية لها سطر في RBAC_REQUIRES (E15.5) */
    public function test_every_admin_action_names_a_known_permission_with_a_role_requirement(): void
    {
        foreach (AdminTools::TOOLS as $name => $tool) {
            foreach ($tool['actions'] as $action => $a) {
                foreach ((array) $a['perm'] as $perm) {
                    $this->assertArrayHasKey($perm, AdminTools::CATALOG, "{$name}:{$action}");
                    $this->assertArrayHasKey($perm, AdminTools::RBAC_REQUIRES, "{$name}:{$action}");
                }
            }
        }
    }

    /** كل قراءة لعرض بمعرّف من النموذج تمر بـ Offer::forBot (العروض الظاهرة للبوت وحدها) */
    public function test_every_offer_id_from_the_model_goes_through_offer_for_bot(): void
    {
        $lines = array_filter(file(app_path('Services/WhatsApp/BotTools.php')), fn ($l) => str_contains($l, "\$in['offer_id']"));

        $this->assertNotEmpty($lines);
        foreach ($lines as $line) {
            $this->assertStringContainsString('Offer::forBot()', $line, trim($line));
        }
    }

    public function test_the_leak_guard_strips_every_registered_name(): void
    {
        $strip = new \ReflectionMethod(BotEngine::class, 'stripToolLeak');
        foreach (array_merge(BotTools::names(), AdminTools::names()) as $name) {
            $out = $strip->invoke(null, "سأستدعي {$name}(x=1) الآن لأجلب لك ما طلبت من العروض المتاحة");
            $this->assertStringNotContainsString($name, $out);
        }
    }

    // ==================== التحويل لا يُطفأ ====================

    public function test_handoff_cannot_be_turned_off_from_any_path(): void
    {
        $this->configureChannel();
        WaBotSetting::current()->forceFill(['tools_config' => ['handoff_to_human' => false, 'save_note' => false]])->save();
        $s = WaBotSetting::current()->fresh();

        $this->assertTrue($s->toolEnabled('handoff_to_human'), 'مفتاح قديم لا يطفئها');
        $this->assertContains('handoff_to_human', array_column(BotTools::declarations($s, new WaConversation), 'name'));
        $this->assertNotContains('save_note', array_column(BotTools::declarations($s, new WaConversation), 'name'));

        $owner = User::factory()->withPermissions(['whatsapp.settings'], 'owner')->create();
        $this->actingAs($owner)->patchJson('/whatsapp/settings', ['version' => (int) $s->settings_version, 'confirm' => true,
            'changes' => ['tools_config' => ['handoff_to_human' => false]]])->assertOk();
        $this->assertArrayNotHasKey('handoff_to_human', WaBotSetting::current()->fresh()->tools_config ?? [], 'الكاتب يُسقط المفتاح المقفل ويمسح القديم');

        $conv = WaConversation::factory()->windowOpen()->create();
        $admin = WaAdminNumber::factory()->create(['phone' => $conv->phone, 'permissions' => ['tools.toggle']]);
        $out = AdminTools::execute('manage_bot_tools', ['action' => 'disable', 'tool' => 'handoff_to_human'], $conv, $admin);
        $this->assertSame('هذه الأداة لا تُطفأ', $out['error']);
        $this->assertArrayNotHasKey('needs_confirmation', $out);
    }

    public function test_the_settings_page_marks_escalating_and_locked_tools(): void
    {
        $this->configureChannel();
        $owner = User::factory()->withPermissions(['whatsapp.settings'], 'owner')->create();

        $this->actingAs($owner)->get('/whatsapp/settings')->assertInertia(fn ($page) => $page
            ->where('toolMeta', fn ($meta) => collect($meta)->firstWhere('key', 'handoff_to_human')['disableable'] === false
                && collect($meta)->where('escalates', true)->pluck('key')->sort()->values()->all() === ['confirm_booking', 'handoff_to_human', 'request_quote']));
    }
}
