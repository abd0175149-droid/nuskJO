<?php

namespace Tests\Feature\WhatsApp;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\WaConversation;
use App\Models\WaLabel;
use App\Services\WhatsApp\LabelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsWhatsAppStaff;
use Tests\TestCase;

/**
 * إدارة قائمة التصنيفات (E29.4): للمدير وحده (whatsapp.settings)، والأسماء بلا أرقام طويلة ولا بريد ولا روابط
 * وبين 2 و30 حرفاً، والتعطيل يُبقي الشريحة باهتة، والحذف للمستعمل يحتاج «إزالة من كل المحادثات». الصفحة تعمل قبل العلم.
 */
class LabelsCrudTest extends TestCase
{
    use BuildsWhatsAppStaff;
    use RefreshDatabase;

    private User $manager;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        config(['audit.extended' => true]);
        $this->manager = $this->staffWith('labels-manager', ['whatsapp.settings']);
        $this->agent = $this->staffWith('agent', [...self::INBOX_PERMISSIONS, 'whatsapp.view_all']);
    }

    private function save(array $data, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->manager)->post('/whatsapp/labels', $data + ['color' => 'amber']);
    }

    public function test_only_the_settings_permission_manages_the_list(): void
    {
        $label = WaLabel::create(['name' => 'VIP', 'color' => 'gold']);
        $this->actingAs($this->agent)->get('/whatsapp/labels')->assertForbidden();
        $this->save(['name' => 'دفع عربون'], $this->agent)->assertForbidden();
        $this->actingAs($this->agent)->put("/whatsapp/labels/{$label->id}", ['name' => 'X1', 'color' => 'red'])->assertForbidden();
        $this->actingAs($this->agent)->delete("/whatsapp/labels/{$label->id}")->assertForbidden();
        $this->actingAs($this->agent)->post('/whatsapp/labels/reorder', ['ids' => [$label->id]])->assertForbidden();
        $this->assertSame(1, WaLabel::count());

        // الصفحة تعمل والعلم مطفأ: القائمة الأولى تُعدّ قبل التفعيل
        $this->actingAs($this->manager)->get('/whatsapp/labels')->assertOk()
            ->assertInertia(fn ($page) => $page->has('labels', 1)->where('labels.0.name', 'VIP')->where('labels.0.usage', 0)->where('enabled', false)
                ->where('colors', WaLabel::COLORS));

        $this->save(['name' => '  ينتظر جواز  '])->assertSessionHasNoErrors();
        $created = WaLabel::where('name', 'ينتظر جواز')->sole();
        $this->assertTrue($created->is_active);
        $this->assertSame($this->manager->id, $created->created_by);
        $this->assertSame(1, $created->sort_order, 'يأتي بعد آخر ترتيب');
        $this->assertSame(1, AuditLog::where('action', 'wa.label_create')->where('entity_id', $created->id)->count());
    }

    public function test_names_that_look_like_personal_data_are_refused(): void
    {
        foreach (['0791234567', 'رقم 12345', 'جواز ١٢٣٤٥', 'هاتف 079 123 45', 'sami@example.com', 'www.example.com', 'https://x.io', 'nusk.jo عرض',
            str_repeat('ع', 31), 'ع'] as $bad) {
            $this->save(['name' => $bad])->assertSessionHasErrors('name');
        }
        $this->save(['name' => 'VIP', 'color' => 'pink'])->assertSessionHasErrors('color');
        $this->assertSame(0, WaLabel::count());

        $this->save(['name' => "تأشيرة\u{202E} قيد الإصدار"])->assertSessionHasNoErrors();
        $this->assertSame('تأشيرة قيد الإصدار', WaLabel::sole()->name, 'محارف الاتجاه تُحذف');
        $this->save(['name' => 'تأشيرة قيد الإصدار'])->assertSessionHasErrors('name');
        $this->save(['name' => 'عمرة 2026'])->assertSessionHasNoErrors();
    }

    public function test_deactivating_keeps_the_faded_chip_on_old_conversations(): void
    {
        $label = WaLabel::create(['name' => 'متابعة بعد العودة', 'color' => 'blue']);
        $conv = WaConversation::factory()->create();
        DB::table('wa_conversation_labels')->insert(['conversation_id' => $conv->id, 'label_id' => $label->id, 'created_at' => now()]);

        $this->actingAs($this->manager)->put("/whatsapp/labels/{$label->id}", ['name' => 'متابعة بعد العودة', 'color' => 'blue', 'is_active' => false])
            ->assertSessionHasNoErrors();

        $this->assertFalse($label->fresh()->is_active);
        $this->assertSame([['id' => $label->id, 'name' => 'متابعة بعد العودة', 'color' => 'blue', 'active' => false]], LabelService::chipsFor([$conv->id])[$conv->id]);
        $this->assertSame([], LabelService::activeList(), 'لا يُختار من جديد');
        $audit = AuditLog::where('action', 'wa.label_update')->sole();
        $this->assertTrue($audit->changes['before']['is_active']);
        $this->assertFalse($audit->changes['after']['is_active']);
    }

    public function test_a_used_label_is_deleted_only_with_detach_all(): void
    {
        $label = WaLabel::create(['name' => 'دفع عربون', 'color' => 'green']);
        $unused = WaLabel::create(['name' => 'VIP', 'color' => 'gold']);
        foreach (WaConversation::factory()->count(2)->create() as $conv) {
            DB::table('wa_conversation_labels')->insert(['conversation_id' => $conv->id, 'label_id' => $label->id, 'created_at' => now()]);
        }

        $this->actingAs($this->manager)->delete("/whatsapp/labels/{$label->id}")->assertSessionHasErrors('label');
        $this->assertNotNull($label->fresh());
        $this->assertSame(2, DB::table('wa_conversation_labels')->count());

        $this->actingAs($this->manager)->delete("/whatsapp/labels/{$label->id}", ['detach_all' => true])->assertSessionHasNoErrors();
        $this->assertNull($label->fresh());
        $this->assertSame(0, DB::table('wa_conversation_labels')->count());
        $this->assertSame(2, AuditLog::where('action', 'wa.label_delete')->sole()->changes['detached']);

        $this->actingAs($this->manager)->delete("/whatsapp/labels/{$unused->id}")->assertSessionHasNoErrors();
        $this->assertSame(0, WaLabel::count());
    }

    public function test_reorder_sets_the_display_order(): void
    {
        $a = WaLabel::create(['name' => 'أ أ', 'color' => 'gray', 'sort_order' => 1]);
        $b = WaLabel::create(['name' => 'ب ب', 'color' => 'gray', 'sort_order' => 2]);

        $this->actingAs($this->manager)->post('/whatsapp/labels/reorder', ['ids' => [$b->id, $a->id]])->assertSessionHasNoErrors();

        $this->assertSame([1, 2], [$b->fresh()->sort_order, $a->fresh()->sort_order]);
    }
}
