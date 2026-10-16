<?php

namespace Tests\Feature\WhatsApp;

use App\Models\User;
use App\Models\WaConversation;
use App\Models\WaLabel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsWhatsAppStaff;
use Tests\TestCase;

/**
 * التصنيفات منفصلة عن مواضيع التوجيه (E29): وضعها أو إزالتها لا يغيّر من وُكّلت إليه المحادثة، ولا يضيف وسم موضوع،
 * ولا يجعلها مرئية لموظف آخر. وحارس نصي: موجّه المواضيع لا يعرف جدول التصنيفات، وخدمة التصنيفات لا تعرف الموجّه.
 */
class LabelsDoNotAffectVisibilityTest extends TestCase
{
    use BuildsWhatsAppStaff;
    use RefreshDatabase;

    /** @return array<string, list<int>> ما يراه كل شخص */
    private function visibility(array $people): array
    {
        return array_map(fn (User $u) => WaConversation::query()->visibleTo($u)->orderBy('id')->pluck('id')->all(), $people);
    }

    private function routingState(): array
    {
        return [
            'assigned' => DB::table('wa_conversations')->orderBy('id')->pluck('assigned_to', 'id')->all(),
            'topics' => DB::table('wa_conversation_topics')->orderBy('id')->get(['conversation_id', 'topic', 'source'])->map(fn ($r) => (array) $r)->all(),
        ];
    }

    public function test_attaching_and_detaching_labels_changes_neither_visibility_nor_assignment(): void
    {
        config(['features.env.wa_labels' => true]);
        $people = [
            'sales' => $this->staffWith('sales-desk', self::INBOX_PERMISSIONS),
            'umrah' => $this->staffWith('umrah-desk', self::INBOX_PERMISSIONS, ['umrah'], true),
            'manager' => $this->staffWith('manager', [...self::INBOX_PERMISSIONS, 'whatsapp.view_all']),
        ];
        $umrahConv = $this->conversationTagged(['umrah']);
        $visaConv = $this->conversationTagged(['visa']);
        $salesConv = $this->conversationTagged([], ['assigned_to' => $people['sales']->id]);
        $untagged = $this->conversationTagged();
        $labels = collect(['ينتظر جواز', 'VIP', 'دفع عربون'])->map(fn ($n) => WaLabel::create(['name' => $n, 'color' => 'amber']));
        $visibleBefore = $this->visibility($people);
        $routingBefore = $this->routingState();

        foreach ($labels as $label) {
            $this->actingAs($people['umrah'])->postJson("/api/whatsapp/conversations/{$umrahConv->id}/labels/{$label->id}")->assertOk();
            $this->actingAs($people['sales'])->postJson("/api/whatsapp/conversations/{$salesConv->id}/labels/{$label->id}")->assertOk();
            $this->actingAs($people['manager'])->postJson("/api/whatsapp/conversations/{$visaConv->id}/labels/{$label->id}")->assertOk();
            $this->actingAs($people['manager'])->postJson("/api/whatsapp/conversations/{$untagged->id}/labels/{$label->id}")->assertOk();
        }
        $this->assertSame(12, DB::table('wa_conversation_labels')->count());
        $this->assertSame($visibleBefore, $this->visibility($people));
        $this->assertSame($routingBefore, $this->routingState(), 'لا توكيل صامت ولا وسم موضوع');

        $this->actingAs($people['manager'])->deleteJson("/api/whatsapp/conversations/{$untagged->id}/labels/{$labels[0]->id}")->assertOk();
        $this->actingAs($people['umrah'])->deleteJson("/api/whatsapp/conversations/{$umrahConv->id}/labels/{$labels[1]->id}")->assertOk();
        $this->assertSame($visibleBefore, $this->visibility($people));
        $this->assertSame($routingBefore, $this->routingState());
    }

    public function test_routing_and_labels_stay_apart_in_the_code(): void
    {
        $router = file_get_contents(app_path('Services/WhatsApp/TopicRouter.php'));
        $this->assertStringNotContainsString('wa_conversation_labels', $router);
        $this->assertStringNotContainsString('LabelService', $router);

        $labels = file_get_contents(app_path('Services/WhatsApp/LabelService.php'));
        $this->assertStringNotContainsString('TopicRouter', $labels);
        $this->assertStringNotContainsString('wa_conversation_topics', $labels);
    }
}
