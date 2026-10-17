<?php

namespace Tests\Feature\WhatsApp;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\User;
use App\Models\WaConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsWhatsAppStaff;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\Concerns\SignsMetaWebhooks;
use Tests\TestCase;

/**
 * ربط المحادثة بعميل يدوياً (E11.8): بصلاحية whatsapp.link_client ولمحادثة يراها الموظف، وبتأكيد الكود في الخادم.
 * كل ربط وفك في سجل التدقيق، والفك يمنع الربط الآلي التالي، والبحث بلا رقم كامل ولا رصيد.
 */
class LinkClientTest extends TestCase
{
    use BuildsWhatsAppStaff;
    use ConfiguresWhatsApp;
    use RefreshDatabase;
    use SignsMetaWebhooks;

    private User $linker;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->configureChannel();
        $this->fakeGraph();
        $this->linker = User::factory()->withPermissions(['whatsapp.view', 'whatsapp.view_all', 'whatsapp.reply', 'whatsapp.link_client', 'clients.view'], 'linker')->create();
        $this->client = Client::factory()->create(['name' => 'عميل مسجّل', 'code' => 'C-10001', 'phone' => '+16505551234']);
    }

    private function link(WaConversation $conv, array $data = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->linker)->postJson("/api/whatsapp/conversations/{$conv->id}/link-client",
            $data + ['client_id' => $this->client->id, 'client_code' => $this->client->code]);
    }

    public function test_it_needs_the_permission(): void
    {
        $plain = User::factory()->withPermissions(['whatsapp.view', 'whatsapp.view_all', 'whatsapp.reply', 'clients.view'], 'plain')->create();

        $this->link(WaConversation::factory()->create(), [], $plain)->assertForbidden();
        $this->actingAs($plain)->getJson('/api/whatsapp/clients/search?q=عميل')->assertForbidden();
    }

    public function test_a_conversation_outside_the_staff_scope_is_not_found(): void
    {
        $scoped = $this->staffWith('desk', ['whatsapp.view', 'whatsapp.link_client', 'clients.view'], ['umrah']);
        $conv = $this->conversationTagged(['visa']);

        $this->link($conv, [], $scoped)->assertNotFound();
        $this->assertNull($conv->fresh()->client_id);
    }

    public function test_the_code_must_match_the_chosen_client(): void
    {
        $conv = WaConversation::factory()->create();

        $this->link($conv, ['client_code' => 'C-99999'])->assertStatus(422)->assertJsonValidationErrors('client_code');
        $this->link($conv, ['client_id' => 9999])->assertStatus(422)->assertJsonValidationErrors('client_id');
        $this->assertNull($conv->fresh()->client_id);
        $this->assertSame(0, AuditLog::count());
    }

    public function test_linking_records_who_when_and_the_previous_client(): void
    {
        $previous = Client::factory()->create();
        $conv = WaConversation::factory()->create(['phone' => null, 'wa_phone' => null, 'wa_user_id' => 'US.1', 'client_id' => $previous->id]);

        $this->link($conv)->assertOk()->assertJsonPath('client.code', 'C-10001');

        $conv->refresh();
        $this->assertSame([$this->client->id, 'manual', $this->linker->id], [$conv->client_id, $conv->client_link, $conv->client_linked_by]);
        $this->assertNotNull($conv->client_linked_at);
        $this->assertNull($conv->financialClientId(), 'بلا رقم: الحاجز المالي باقٍ رغم الربط');
        $row = AuditLog::where('action', 'wa.link_client')->sole();
        $this->assertSame([$previous->id, $this->client->id, 'C-10001', true, $this->linker->id],
            [$row->changes['client_from'], $row->changes['client_to'], $row->changes['client_code'], $row->changes['phoneless'], $row->user_id]);
    }

    public function test_unlinking_blocks_the_next_automatic_link(): void
    {
        $conv = WaConversation::factory()->create(['phone' => '16505551234', 'wa_phone' => '16505551234', 'client_id' => $this->client->id, 'client_link' => 'auto']);

        $this->actingAs($this->linker)->deleteJson("/api/whatsapp/conversations/{$conv->id}/link-client")->assertOk();
        $this->assertSame([null, 'blocked'], [$conv->fresh()->client_id, $conv->fresh()->client_link]);
        $this->assertSame($this->client->id, AuditLog::where('action', 'wa.unlink_client')->sole()->changes['client_from']);

        $this->postMetaWebhook('text.json')->assertOk();

        $this->assertNull($conv->fresh()->client_id, 'الرسالة التالية لا تعيد الربط');
    }

    public function test_search_returns_at_most_ten_masked_results_without_balances(): void
    {
        Client::factory()->count(12)->create(['name' => 'عميل تجريبي']);

        $res = $this->actingAs($this->linker)->getJson('/api/whatsapp/clients/search?q=عميل')->assertOk();

        $this->assertCount(10, $res->json('clients'));
        $this->assertSame(['id', 'name', 'code', 'phone_masked'], array_keys($res->json('clients.0')));
        $hit = $this->actingAs($this->linker)->getJson('/api/whatsapp/clients/search?q=C-10001')->json('clients.0');
        $this->assertSame('•••234', $hit['phone_masked']);
        $this->assertStringNotContainsString('6505551234', json_encode($res->json()));
        $this->actingAs($this->linker)->getJson('/api/whatsapp/clients/search?q=a')->assertStatus(422);
    }

    public function test_search_ignores_deleted_clients_and_linking_refuses_them(): void
    {
        $deleted = Client::factory()->create(['name' => 'محذوف', 'code' => 'C-DEL']);
        $deleted->delete();

        $this->assertSame([], $this->actingAs($this->linker)->getJson('/api/whatsapp/clients/search?q=محذوف')->json('clients'));
        $this->link(WaConversation::factory()->create(), ['client_id' => $deleted->id, 'client_code' => 'C-DEL'])->assertJsonValidationErrors('client_id');
        $this->assertSame(0, DB::table('wa_conversations')->whereNotNull('client_id')->count());
    }
}
