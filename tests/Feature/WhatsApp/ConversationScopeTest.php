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

    /**
     * كل مسار فيه {conversation}: [الطريقة، الحمولة، وحالة المحادثة التي ينجح عليها إن لزمت،
     * وصلاحيات إضافية فوق INBOX_PERMISSIONS، وحالة النجاح المتوقعة داخل النطاق إن لم تكن 200].
     * ConversationRouteCoverageTest يفشل إن غاب عنها مسار.
     */
    public const ROUTES = [
        'messages' => ['GET', []],
        'send' => ['POST', ['body' => 'مرحباً']],
        'toggle-bot' => ['POST', []],
        'resolve' => ['POST', []],
        'topic' => ['POST', ['topic' => 'visa', 'attach' => true]],
        'assign' => ['POST', ['user_id' => null]],
        'read' => ['POST', []],
        // طلب الرقم لمحادثة بلا رقم وحدها، بالمعرّف في وضع staff
        'request-contact' => ['POST', [], ['phone' => null, 'wa_phone' => null, 'wa_user_id' => 'US.13491208655302741918']],
        // الربط (POST) بحمولة عميل حقيقي يغطيه LinkClientTest؛ هنا فك الربط على المسار نفسه
        'link-client' => ['DELETE', []],
        // متابعة الصندوق (E17): DELETE على snooze وpin بالربط نفسه (ConversationStateTest)
        'close' => ['POST', []],
        'reopen' => ['POST', []],
        'snooze' => ['POST', ['minutes' => 60]],
        'pin' => ['POST', []],
        'mark-unread' => ['POST', []],
        // للمدير (whatsapp.settings): داخل النطاق بلا وارد معلّق يرد 422، لا 404
        'reprocess' => ['POST', [], [], ['whatsapp.settings'], 422],
        // ملء رد جاهز: داخل النطاق بلا معرّف الرد يرد 422 (الربط قبل التحقق)، وخارجه 404
        'quick-replies/render' => ['GET', [], [], [], 422],
        // E29: التصنيف والتثبيت والحقائق تنجح داخل النطاق؛ الترجمة يردها تأكيد الطبقة المدفوعة (409) بعد الربط،
        // والإرسال المترجم بلا رمز معاينة صالح 422. {label} و{message} يملؤهما fill()
        'labels/{label}' => ['POST', []],
        'pin-note' => ['PUT', ['note' => 'يفضّل غرفة ثلاثية قريبة من الحرم']],
        'messages/{message}/pin' => ['POST', []],
        'facts' => ['GET', []],
        'messages/{message}/translate' => ['POST', [], [], [], 409],
        'translate-reply' => ['POST', ['text' => 'السعر 150 دينار للشخص', 'target' => 'en'], [], [], 409],
        'send-translated' => ['POST', ['body' => 'Price 150 JOD', 'translation' => ['source_text' => 'السعر 150 دينار', 'target' => 'en', 'check_token' => 'x']], [], [], 422],
        // تصدير محادثة (E30): بصلاحيته وسبب مكتوب، والسياج نفسه (ConversationExportTest)
        'export' => ['GET', ['format' => 'txt', 'reason' => 'طلب اطّلاع من العميل'], [], ['whatsapp.export']],
        // ملف من الصندوق (E32): داخل النطاق بلا Idempotency-Key يرد 422 (الربط قبل التحقق)، وخارجه 404
        'send-file' => ['POST', [], [], [], 422],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureChannel();
        $this->fakeGraph();
        \App\Models\Setting::set('wa_identity_mode', \App\Services\WhatsApp\IdentityMode::STAFF);
        config(['features.env.reprocess_button' => true, 'features.env.quick_replies' => true,
            'features.env.wa_labels' => true, 'features.env.thread_pins' => true, 'features.env.staff_translation' => true,
            'features.env.conversation_export' => true, 'features.env.staff_file_send' => true]);
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

        return $this->actingAs($user)->json($method, "/api/whatsapp/conversations/{$id}/".$this->fill($route, $user, $conversation), $payload);
    }

    /**
     * معاملات بعد {conversation} (E29): تصنيف فعّال، ورسالة واردة من المحادثة نفسها إن كانت في نطاق المستخدم.
     * خارج النطاق يرد الربط 404 قبل أي معامل آخر، فلا تُنشأ رسالة تغيّر حالة المحادثة المقارنة.
     */
    private function fill(string $route, User $user, WaConversation|int|string $conversation): string
    {
        if (str_contains($route, '{label}')) {
            $route = str_replace('{label}', (string) \App\Models\WaLabel::firstOrCreate(['name' => 'ينتظر جواز'], ['color' => 'amber'])->id, $route);
        }
        if (str_contains($route, '{message}')) {
            $reachable = $conversation instanceof WaConversation && WaConversation::query()->visibleTo($user)->whereKey($conversation->id)->exists();
            $id = $reachable ? \App\Models\WaMessage::factory()->inbound()->create(['conversation_id' => $conversation->id, 'body' => 'Hello, I need a visa for two'])->id : 0;
            $route = str_replace('{message}', (string) $id, $route);
        }

        return $route;
    }

    private static function permissionsFor(string $route): array
    {
        return [...self::INBOX_PERMISSIONS, ...(self::ROUTES[$route][3] ?? [])];
    }

    #[DataProvider('routes')]
    public function test_conversation_outside_scope_is_not_found_and_left_untouched(string $route): void
    {
        $specialist = $this->staffWith('umrah-desk', self::permissionsFor($route), ['umrah']);
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
        $plain = $this->staffWith('desk', self::permissionsFor($route));
        $conversation = $this->conversationTagged(['umrah']);
        $before = $this->conversationState($conversation);

        $this->hit($plain, $conversation, $route)->assertNotFound();

        $this->assertSame($before, $this->conversationState($conversation));
        Http::assertNothingSent();
    }

    #[DataProvider('routes')]
    public function test_topic_specialist_reaches_a_conversation_with_their_topic(string $route): void
    {
        $specialist = $this->staffWith('umrah-desk', self::permissionsFor($route), ['umrah']);

        $this->hit($specialist, $this->conversationTagged(['umrah'], self::ROUTES[$route][2] ?? []), $route)->assertStatus(self::ROUTES[$route][4] ?? 200);
    }

    #[DataProvider('routes')]
    public function test_assignee_reaches_an_untagged_conversation(string $route): void
    {
        $assignee = $this->staffWith('desk', self::permissionsFor($route));

        $this->hit($assignee, $this->conversationTagged([], ['assigned_to' => $assignee->id] + (self::ROUTES[$route][2] ?? [])), $route)->assertStatus(self::ROUTES[$route][4] ?? 200);
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
