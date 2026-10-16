<?php

namespace Tests\Feature\Broadcasting;

use App\Events\DataUpdated;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * تفويض القنوات الخاصة (E28): user.{id} لصاحبها النشط وحده، وstaff لأي موظف نشط مصادَق، ولا قناة غيرهما.
 * المعطّل والزائر لا يحصلان على توقيع، والطلبات فوق الحد في الدقيقة 429.
 */
class ChannelAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // محرك يوقّع فعلاً (بلا أي نداء شبكة): القنوات تُسجَّل على المحرك الافتراضي لحظة تحميل الملف
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => '1',
            'broadcasting.connections.reverb.options.host' => '127.0.0.1',
        ]);
        require base_path('routes/channels.php');
    }

    private function auth(?User $user, string $channel, bool $json = false)
    {
        $request = $user ? $this->actingAs($user) : $this;
        $payload = ['socket_id' => '1234.5678', 'channel_name' => $channel];

        // pusher-js يرسل نموذجاً بلا Accept: application/json
        return $json ? $request->postJson('/broadcasting/auth', $payload) : $request->post('/broadcasting/auth', $payload);
    }

    public function test_a_user_is_authorized_for_their_own_channel_only(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();

        $this->auth($me, "private-user.{$me->id}")->assertOk()->assertJsonStructure(['auth']);
        $this->auth($me, "private-user.{$other->id}")->assertForbidden();
    }

    public function test_the_staff_channel_is_for_active_signed_in_users(): void
    {
        $this->auth(User::factory()->create(), 'private-staff')->assertOk()->assertJsonStructure(['auth']);
    }

    public function test_entity_updates_go_to_the_private_staff_channel_after_commit(): void
    {
        $event = new DataUpdated('agent', 'created', 5);

        $this->assertSame(['private-staff'], array_map(fn ($c) => $c->name, $event->broadcastOn()));
        $this->assertInstanceOf(ShouldDispatchAfterCommit::class, $event);
    }

    public function test_the_old_public_channel_and_unknown_channels_are_refused(): void
    {
        $me = User::factory()->create();

        $this->auth($me, 'private-nusuk-updates')->assertForbidden();
        $this->auth($me, 'nusuk-updates')->assertForbidden();
    }

    public function test_a_disabled_user_gets_no_signature_and_is_signed_out(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['is_active' => false])->save();

        $response = $this->auth($user, "private-user.{$user->id}");
        $this->assertNotSame(200, $response->getStatusCode());
        $this->assertArrayNotHasKey('auth', (array) json_decode((string) $response->getContent(), true));
        $this->assertGuest();

        $user->refresh();
        $this->auth($user, "private-user.{$user->id}", json: true)->assertUnauthorized();
    }

    public function test_guests_get_no_signature(): void
    {
        $user = User::factory()->create();

        $this->auth(null, "private-user.{$user->id}", json: true)->assertUnauthorized();
        $this->assertNotSame(200, $this->auth(null, 'private-staff')->getStatusCode());
    }

    public function test_requests_above_the_limit_in_a_minute_get_429(): void
    {
        config(['broadcasting.auth_limit.per_user' => 5]);
        $user = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->auth($user, "private-user.{$user->id}")->assertOk();
        }
        $this->auth($user, "private-user.{$user->id}")->assertStatus(429);
    }

    public function test_the_default_limit_is_sixty_a_minute_per_user(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 60; $i++) {
            $this->auth($user, 'private-staff')->assertOk();
        }
        $this->auth($user, 'private-staff')->assertStatus(429);
    }
}
