<?php

namespace Tests\Feature\WhatsApp;

use App\Models\AuditLog;
use App\Models\WaMessage;
use App\Models\WaThreadPin;
use App\Services\WhatsApp\NoteGuard;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsWhatsAppStaff;
use Tests\TestCase;

/**
 * الملاحظة المثبتة والرسائل المثبتة (E29.6): ملاحظة واحدة لكل محادثة (الفهرس الجزئي)، و3 رسائل حداً، ورسالة محادثة
 * أخرى 404 ولو كانت في النطاق، والتفاعل ورسالة النظام لا يُثبَّتان، وحارس الجوازات والهويات والبطاقات، ونص الملاحظة
 * لا يدخل سجل التدقيق، والعلم المطفأ 404.
 */
class ThreadPinsTest extends TestCase
{
    use BuildsWhatsAppStaff;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['features.env.thread_pins' => true, 'audit.extended' => true]);
    }

    private function url(int $convId, string $path): string
    {
        return "/api/whatsapp/conversations/{$convId}/{$path}";
    }

    public function test_one_note_per_conversation_saved_and_cleared_with_its_editor(): void
    {
        $agent = $this->staffWith('agent', [...self::INBOX_PERMISSIONS, 'whatsapp.view_all']);
        $conv = $this->conversationTagged();

        $this->actingAs($agent)->putJson($this->url($conv->id, 'pin-note'), ['note' => "يفضّل غرفة\nثلاثية \u{202E}قريبة"])
            ->assertOk()->assertJsonPath('pinned.note.text', 'يفضّل غرفة ثلاثية قريبة')->assertJsonPath('pinned.note.by', $agent->name);
        $this->actingAs($agent)->putJson($this->url($conv->id, 'pin-note'), ['note' => 'دفع عربون 150 دينار في 2026-11-20'])->assertOk();
        $this->assertSame('دفع عربون 150 دينار في 2026-11-20', WaThreadPin::where('kind', 'note')->sole()->note);

        // الفهرس الجزئي يمنع ملاحظة ثانية حتى بكتابة مباشرة
        try {
            DB::table('wa_thread_pins')->insert(['conversation_id' => $conv->id, 'kind' => 'note', 'note' => 'ثانية', 'created_at' => now(), 'updated_at' => now()]);
            $this->fail('ملاحظة ثانية لنفس المحادثة');
        } catch (UniqueConstraintViolationException) {
        }

        $audit = AuditLog::where('action', 'wa.pin_set')->latest('id')->first();
        $this->assertSame(['kind' => 'note', 'len' => 33], $audit->changes);
        $this->assertStringNotContainsString('عربون', json_encode(AuditLog::all()->toArray(), JSON_UNESCAPED_UNICODE), 'نص الملاحظة لا يدخل التدقيق');

        $this->actingAs($agent)->deleteJson($this->url($conv->id, 'pin-note'))->assertOk()->assertJsonPath('pinned.note', null);
        $this->assertSame(0, WaThreadPin::count());
        $this->assertSame(1, AuditLog::where('action', 'wa.pin_clear')->count());
    }

    public function test_the_guard_refuses_passports_ids_and_cards_but_keeps_dates_and_our_numbers(): void
    {
        $agent = $this->staffWith('agent', [...self::INBOX_PERMISSIONS, 'whatsapp.view_all']);
        $conv = $this->conversationTagged();

        foreach (['جوازه A12345678', '9876543210123', 'هويته ٩٨٧٦٥٤٣٢١٠', 'بطاقة 4111 1111 1111 1111', 'جواز N 1234567', str_repeat('ع', 501)] as $bad) {
            $this->actingAs($agent)->putJson($this->url($conv->id, 'pin-note'), ['note' => $bad])->assertStatus(422);
        }
        $this->actingAs($agent)->putJson($this->url($conv->id, 'pin-note'), ['note' => 'A12345678'])
            ->assertStatus(422)->assertJsonPath('errors.note.0', NoteGuard::MESSAGE);
        $this->assertSame(0, WaThreadPin::count());

        foreach (['2026-11-20 غرفة ثلاثية', 'طلب QR-20261016-0001 بانتظار التسعير', 'فاتورة INV-20261016-0012 والعميل CLT-007', 'السعر 1,450 دينار لشخصين'] as $ok) {
            $this->assertFalse(NoteGuard::looksSensitive($ok), $ok);
        }
        $this->actingAs($agent)->putJson($this->url($conv->id, 'pin-note'), ['note' => '2026-11-20 غرفة ثلاثية'])->assertOk();
    }

    public function test_up_to_three_messages_of_this_conversation_and_no_reactions(): void
    {
        $agent = $this->staffWith('agent', [...self::INBOX_PERMISSIONS, 'whatsapp.view_all']);
        $conv = $this->conversationTagged();
        $other = $this->conversationTagged();
        $msgs = WaMessage::factory()->count(4)->inbound()->create(['conversation_id' => $conv->id]);
        $foreign = WaMessage::factory()->inbound()->create(['conversation_id' => $other->id]);
        $reaction = WaMessage::factory()->inbound()->create(['conversation_id' => $conv->id, 'msg_type' => 'reaction', 'body' => '👍']);

        $this->actingAs($agent)->postJson($this->url($conv->id, "messages/{$foreign->id}/pin"))->assertNotFound();
        $this->actingAs($agent)->postJson($this->url($conv->id, "messages/{$reaction->id}/pin"))->assertStatus(422);
        foreach ($msgs->take(3) as $m) {
            $this->actingAs($agent)->postJson($this->url($conv->id, "messages/{$m->id}/pin"))->assertOk();
        }
        $this->actingAs($agent)->postJson($this->url($conv->id, "messages/{$msgs[0]->id}/pin"))->assertOk()->assertJsonCount(3, 'pinned.messages');
        $this->actingAs($agent)->postJson($this->url($conv->id, "messages/{$msgs[3]->id}/pin"))
            ->assertStatus(422)->assertJsonPath('errors.message.0', 'ألغِ تثبيت رسالة أولاً: الحد 3 رسائل مثبتة');
        $this->assertSame(3, WaThreadPin::where('kind', 'message')->count());

        $response = $this->actingAs($agent)->deleteJson($this->url($conv->id, "messages/{$msgs[1]->id}/pin"))->assertOk();
        $this->assertSame([$msgs[0]->id, $msgs[2]->id], array_column($response->json('pinned.messages'), 'id'));
        $this->actingAs($agent)->postJson($this->url($conv->id, "messages/{$msgs[3]->id}/pin"))->assertOk();
        $this->assertSame(0, WaThreadPin::where('conversation_id', $other->id)->count());
    }

    public function test_scope_permissions_and_the_flag(): void
    {
        $umrah = $this->staffWith('umrah-desk', self::INBOX_PERMISSIONS, ['umrah']);
        $viewer = $this->staffWith('viewer', ['whatsapp.view', 'whatsapp.view_all']);
        $foreign = $this->conversationTagged(['visa']);
        $mine = $this->conversationTagged(['umrah']);

        $this->actingAs($umrah)->putJson($this->url($foreign->id, 'pin-note'), ['note' => 'ملاحظة'])->assertNotFound();
        $this->actingAs($viewer)->putJson($this->url($mine->id, 'pin-note'), ['note' => 'ملاحظة'])->assertForbidden();
        config(['features.env.thread_pins' => false]);
        $this->actingAs($umrah)->putJson($this->url($mine->id, 'pin-note'), ['note' => 'ملاحظة'])->assertNotFound();
        $this->actingAs($umrah)->getJson($this->url($mine->id, 'facts'))->assertNotFound();
        $this->assertSame(0, WaThreadPin::count());
    }
}
