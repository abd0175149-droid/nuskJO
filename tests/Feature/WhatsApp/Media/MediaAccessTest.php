<?php

namespace Tests\Feature\WhatsApp\Media;

use App\Jobs\ArchiveWhatsAppMedia;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\WaConversation;
use App\Models\WaMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsWhatsAppStaff;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * العرض والتنزيل (E14.6–E14.8): النطاق بـ 404 والصلاحية بـ 403، وattachment بترويسات تمنع التنفيذ، والاسم منظّف،
 * والتدقيق بلا اسم ملف، والتنزيل اليدوي بشروطه، وحقل media في الخيط بلا مسار.
 */
class MediaAccessTest extends TestCase
{
    use BuildsWhatsAppStaff;
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    private const MEDIA = ['whatsapp.view', 'whatsapp.reply', 'whatsapp.media'];

    private WaConversation $conv;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('wa_media');
        $this->configureChannel();
        $this->conv = $this->conversationTagged(['umrah']);
    }

    private function stored(array $attrs = [], ?WaConversation $conv = null, string $bytes = 'JPEGDATA'): WaMessage
    {
        $path = '2026/10/0199f3a2-1111-7000-8000-000000000001.jpg';
        Storage::disk('wa_media')->put($path, $bytes);

        return WaMessage::factory()->inbound()->create($attrs + ['conversation_id' => ($conv ?? $this->conv)->id, 'msg_type' => 'image',
            'media_id' => '1104480873777230', 'media_status' => 'stored', 'media_path' => $path, 'media_mime' => 'image/jpeg', 'media_size' => strlen($bytes)]);
    }

    private function url(WaMessage $m, string $q = ''): string
    {
        return "/api/whatsapp/messages/{$m->id}/media{$q}";
    }

    public function test_scope_comes_before_permission_and_both_are_enforced(): void
    {
        $m = $this->stored();
        $outsider = $this->staffWith('desk', self::MEDIA, ['visa']);
        $noMedia = $this->staffWith('desk2', ['whatsapp.view', 'whatsapp.reply'], ['umrah']);
        $specialist = $this->staffWith('desk3', self::MEDIA, ['umrah']);

        $this->actingAs($outsider)->get($this->url($m))->assertNotFound();
        $this->actingAs($noMedia)->get($this->url($m))->assertForbidden();
        $this->actingAs($specialist)->get($this->url($m))->assertOk();
        $this->actingAs($specialist)->get('/api/whatsapp/messages/abc/media')->assertNotFound();
    }

    public function test_admin_number_chats_stay_hidden_without_admin_chats(): void
    {
        $adminChat = $this->conversationTagged(['umrah']);
        $adminChat->forceFill(['is_admin_chat' => true])->save();
        $m = $this->stored([], $adminChat);

        $this->actingAs($this->staffWith('desk', [...self::MEDIA, 'whatsapp.view_all'], ['umrah']))->get($this->url($m))->assertNotFound();
        $this->actingAs($this->staffWith('boss', [...self::MEDIA, 'whatsapp.view_all', 'whatsapp.admin_chats']))->get($this->url($m))->assertOk();
    }

    public function test_only_a_stored_file_is_served_and_a_vanished_file_becomes_missing(): void
    {
        $user = $this->staffWith('desk', self::MEDIA, ['umrah']);
        $pending = WaMessage::factory()->inbound()->create(['conversation_id' => $this->conv->id, 'msg_type' => 'image', 'media_status' => 'pending']);
        $this->actingAs($user)->get($this->url($pending))->assertNotFound();

        $m = $this->stored();
        Storage::disk('wa_media')->delete($m->media_path);
        $this->actingAs($user)->get($this->url($m))->assertNotFound();
        $this->assertSame('missing', $m->fresh()->media_status);
    }

    public function test_every_response_is_an_inert_attachment(): void
    {
        $user = $this->staffWith('desk', self::MEDIA, ['umrah']);
        $res = $this->actingAs($user)->get($this->url($this->stored()));

        $res->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Cross-Origin-Resource-Policy', 'same-origin')
            ->assertHeader('Content-Type', 'image/jpeg')->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringStartsWith('attachment;', $res->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', $res->headers->get('Cache-Control'));
        $this->assertStringContainsString('sandbox', $res->headers->get('Content-Security-Policy'));

        foreach (['video/webm', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'image/svg+xml'] as $mime) {
            $m = $this->stored(['media_mime' => $mime]);
            $this->assertSame('application/octet-stream', $this->actingAs($user)->get($this->url($m))->headers->get('Content-Type'), $mime);
        }
    }

    public function test_the_file_name_is_cleaned_and_takes_the_detected_extension(): void
    {
        $user = $this->staffWith('desk', self::MEDIA, ['umrah']);
        $m = $this->stored(['msg_type' => 'document', 'media_filename' => "\u{202E}gpj.exe"]);

        $disposition = $this->actingAs($user)->get($this->url($m))->headers->get('Content-Disposition');

        $this->assertStringContainsString('filename=document-'.$m->id.'.jpg;', $disposition);
        $this->assertStringContainsString("filename*=utf-8''gpj.jpg", $disposition);
        $this->assertStringNotContainsString('exe', $disposition);
        $this->assertStringNotContainsString('%E2%80%AE', $disposition);
    }

    public function test_views_are_sampled_and_every_download_is_audited_without_a_file_name(): void
    {
        $user = $this->staffWith('desk', self::MEDIA, ['umrah']);
        $m = $this->stored(['media_filename' => 'passport-scan.jpg']);

        $this->actingAs($user)->get($this->url($m))->assertOk();
        $this->actingAs($user)->get($this->url($m))->assertOk();
        $this->actingAs($user)->get($this->url($m, '?download=1'))->assertOk();
        $this->actingAs($user)->get($this->url($m, '?download=1'))->assertOk();

        $this->assertSame(1, AuditLog::where('action', 'wa.media_view')->count());
        $this->assertSame(2, AuditLog::where('action', 'wa.media_download')->count());
        $this->assertStringNotContainsString('passport', AuditLog::pluck('changes')->toJson());
        $this->assertSame($this->conv->id, AuditLog::where('action', 'wa.media_view')->sole()->changes['conv']);
    }

    public function test_manual_refetch_has_its_rules(): void
    {
        Queue::fake();
        $user = $this->staffWith('desk', self::MEDIA, ['umrah']);
        $big = WaMessage::factory()->inbound()->create(['conversation_id' => $this->conv->id, 'msg_type' => 'document', 'media_id' => '1104480873777231', 'media_status' => 'too_large', 'media_size' => 40 * 1024 * 1024]);
        $refetch = fn (WaMessage $m, ?User $u = null) => $this->actingAs($u ?? $user)->postJson("/api/whatsapp/messages/{$m->id}/media/refetch");

        $refetch($big, $this->staffWith('viewer', ['whatsapp.view', 'whatsapp.media'], ['umrah']))->assertForbidden();
        $refetch($big)->assertOk()->assertJsonPath('media.status', 'pending');
        Queue::assertPushedOn('wa-media', ArchiveWhatsAppMedia::class, fn ($job) => $job->messageId === $big->id && $job->capOverride === 100 * 1024 * 1024);
        $this->assertSame(100, AuditLog::where('action', 'wa.media_refetch')->sole()->changes['cap_mb']);

        $old = WaMessage::factory()->inbound()->create(['conversation_id' => $this->conv->id, 'msg_type' => 'document', 'media_id' => 'x', 'media_status' => 'failed', 'created_at' => now()->subDays(7)]);
        $refetch($old)->assertStatus(422)->assertJsonPath('message', 'انتهت صلاحية الملف عند ميتا — اطلب من العميل إرساله ثانية.');
        $refetch($this->stored())->assertStatus(422);

        foreach (range(1, 5) as $i) {
            $m = WaMessage::factory()->inbound()->create(['conversation_id' => $this->conv->id, 'msg_type' => 'image', 'media_id' => "m{$i}", 'media_status' => 'failed']);
            $refetch($m)->assertStatus($i < 5 ? 200 : 429);
        }
    }

    public function test_the_thread_carries_state_and_permissions_but_no_path(): void
    {
        $m = $this->stored(['media_filename' => 'booking.jpg']);
        $withMedia = $this->staffWith('desk', self::MEDIA, ['umrah']);
        $without = $this->staffWith('desk2', ['whatsapp.view', 'whatsapp.reply'], ['umrah']);

        $json = $this->actingAs($withMedia)->getJson("/api/whatsapp/conversations/{$this->conv->id}/messages")->assertOk();
        $json->assertJsonPath('messages.0.media.status', 'stored')->assertJsonPath('messages.0.media.viewable', true)
            ->assertJsonPath('messages.0.media.previewable', true)->assertJsonPath('messages.0.media.name', 'booking.jpg');
        $this->assertStringNotContainsString($m->media_path, $json->getContent());
        $this->assertStringNotContainsString('1104480873777230', $json->getContent());

        $this->actingAs($without)->getJson("/api/whatsapp/conversations/{$this->conv->id}/messages")
            ->assertJsonPath('messages.0.media.viewable', false)->assertJsonPath('messages.0.media.name', null);
    }

    public function test_polling_turns_pending_into_viewable(): void
    {
        $user = $this->staffWith('desk', self::MEDIA, ['umrah']);
        $m = WaMessage::factory()->inbound()->create(['conversation_id' => $this->conv->id, 'msg_type' => 'image', 'media_status' => 'pending', 'media_mime' => 'image/jpeg']);
        $since = now()->subSecond()->format('Y-m-d H:i:s');
        $m->update(['media_status' => 'stored', 'media_path' => '2026/10/a.jpg']);

        $this->actingAs($user)->getJson("/api/whatsapp/conversations/{$this->conv->id}/messages?after={$m->id}&since=".urlencode($since))
            ->assertJsonPath('updates.0.id', $m->id)->assertJsonPath('updates.0.media.viewable', true);
    }
}
