<?php

namespace Tests\Feature\WhatsApp;

use App\Models\User;
use App\Models\WaConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\BuildsWhatsAppStaff;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * نطاق المحادثات: الموظف بلا whatsapp.view_all يصل إلى ما يحمل وسماً من اختصاصه أو ما وُكّل له، وحسب.
 * غير ذلك 404 بلا بيانات ولا أثر ولا إرسال، على كل مسار فيه {conversation}.
 */
#[Group('INV-17')]
class ConversationScopeTest extends TestCase
{
    use BuildsWhatsAppStaff;
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    /** كل مسار فيه {conversation}: [الطريقة، الحمولة]. ConversationRouteCoverageTest يفشل إن غاب عنها مسار. */
    public const ROUTES = [
        'messages' => ['GET', []],
        'send' => ['POST', ['body' => 'مرحباً']],
        'toggle-bot' => ['POST', []],
        'resolve' => ['POST', []],
        'topic' => ['POST', ['topic' => 'visa', 'attach' => true]],
        'assign' => ['POST', ['user_id' => null]],
        'read' => ['POST', []],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureChannel();
        $this->fakeGraph();
    }

    public static function routes(): array
    {
        $keys = array_keys(self::ROUTES);

        return array_combine($keys, array_map(fn ($k) => [$k], $keys));
    }

    private function hit(User $user, WaConversation|int|string $conversation, string $route)
    {
        [$method, $payload] = self::ROUTES[$route];
        $id = $conversation instanceof WaConversation ? $conversation->id : $conversation;

        return $this->actingAs($user)->json($method, "/api/whatsapp/conversations/{$id}/{$route}", $payload);
    }

    #[DataProvider('routes')]
    public function test_conversation_outside_scope_is_not_found_and_left_untouched(string $route): void
    {
        $specialist = $this->staffWith('umrah-desk', self::INBOX_PERMISSIONS, ['umrah']);
        $conversation = $this->conversationTagged([], ['unread_count' => 4, 'needs_attention' => true]);
        $before = $this->conversationState($conversation);

        $response = $this->hit($specialist, $conversation, $route)->assertNotFound();

        $this->assertStringNotContainsString($conversation->phone, $response->getContent());
        $this->assertSame($before, $this->conversationState($conversation));
        Http::assertNothingSent();
    }

    #[DataProvider('routes')]
    public function test_staff_without_a_topic_cannot_reach_a_tagged_conversation(string $route): void
    {
        $plain = $this->staffWith('desk', self::INBOX_PERMISSIONS);
        $conversation = $this->conversationTagged(['umrah']);
        $before = $this->conversationState($conversation);

        $this->hit($plain, $conversation, $route)->assertNotFound();

        $this->assertSame($before, $this->conversationState($conversation));
        Http::assertNothingSent();
    }

    #[DataProvider('routes')]
    public function test_topic_specialist_reaches_a_conversation_with_their_topic(string $route): void
    {
        $specialist = $this->staffWith('umrah-desk', self::INBOX_PERMISSIONS, ['umrah']);

        $this->hit($specialist, $this->conversationTagged(['umrah']), $route)->assertOk();
    }

    #[DataProvider('routes')]
    public function test_assignee_reaches_an_untagged_conversation(string $route): void
    {
        $assignee = $this->staffWith('desk', self::INBOX_PERMISSIONS);

        $this->hit($assignee, $this->conversationTagged([], ['assigned_to' => $assignee->id]), $route)->assertOk();
    }

    public function test_view_all_reaches_any_conversation_but_unknown_ids_are_still_not_found(): void
    {
        $manager = $this->staffWith('manager', [...self::INBOX_PERMISSIONS, 'whatsapp.view_all']);

        $this->hit($manager, $this->conversationTagged(), 'messages')->assertOk();
        $this->hit($manager, 999999, 'messages')->assertNotFound();
        $this->hit($manager, 'abc', 'messages')->assertNotFound();
    }

    public function test_refusals_are_logged_with_ids_only(): void
    {
        Log::spy();
        $specialist = $this->staffWith('umrah-desk', self::INBOX_PERMISSIONS, ['umrah']);
        $conversation = $this->conversationTagged();

        $this->hit($specialist, $conversation, 'messages')->assertNotFound();

        Log::shouldHaveReceived('notice')->once()
            ->with('wa.fence.denied', ['user' => $specialist->id, 'conversation' => $conversation->id]);
    }

    public function test_list_and_unread_total_cover_visible_conversations_only(): void
    {
        $specialist = $this->staffWith('umrah-desk', self::INBOX_PERMISSIONS, ['umrah']);
        $tagged = $this->conversationTagged(['umrah'], ['unread_count' => 2]);
        $assigned = $this->conversationTagged([], ['unread_count' => 3, 'assigned_to' => $specialist->id]);
        $this->conversationTagged(['visa'], ['unread_count' => 7, 'needs_attention' => true]);
        $this->conversationTagged([], ['unread_count' => 11, 'bot_enabled' => false]);
        $visible = [$tagged->id, $assigned->id];

        $response = $this->actingAs($specialist)->getJson('/api/whatsapp/conversations')->assertOk();
        $this->assertEqualsCanonicalizing($visible, array_column($response->json('conversations'), 'id'));
        $this->assertSame(5, $response->json('unread_total'));

        // المرشّحات تضيّق ولا توسّع
        foreach ([['topic' => 'visa'], ['filter' => 'unread'], ['filter' => 'attention'], ['filter' => 'human'], ['filter' => 'mine'], ['search' => '962']] as $params) {
            $ids = array_column($this->actingAs($specialist)->getJson('/api/whatsapp/conversations?'.http_build_query($params))->json('conversations'), 'id');
            $this->assertSame([], array_values(array_diff($ids, $visible)), json_encode($params));
        }
    }
}
