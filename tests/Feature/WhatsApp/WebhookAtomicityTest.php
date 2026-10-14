<?php

namespace Tests\Feature\WhatsApp;

use App\Jobs\ProcessWhatsAppReply;
use App\Models\WaConversation;
use App\Models\WaMessage;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\Concerns\SignsMetaWebhooks;
use Tests\TestCase;

/**
 * تخزين الوارد ذري (المحادثة والرسالة والنافذة أو لا شيء)، وعطل القاعدة وحده يرد 503 خلف العلم webhook_5xx
 * فتعيد ميتا الإرسال. ما خُزّن قبل خلل لاحق في الحمولة نفسها يُمرَّر للبوت، ومكرر وارد بلا رد يُعاد تمريره.
 */
#[Group('INV-02')]
class WebhookAtomicityTest extends TestCase
{
    use ConfiguresWhatsApp, RefreshDatabase, SignsMetaWebhooks;

    private const ALICE = '16505551234';

    private const BOB = '16505559876';

    /** wamid => الاستثناء الذي يُرمى عند إدراج الرسالة */
    private array $failOn = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->fakeGraph();
        $this->configureChannel();

        WaMessage::creating(function (WaMessage $m) {
            if (isset($this->failOn[$m->wamid])) {
                throw $this->failOn[$m->wamid];
            }
        });
    }

    private function message(string $wamid, string $from, string $body = 'هل العرض متاح؟'): array
    {
        return ['from' => $from, 'id' => $wamid, 'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => $body]];
    }

    /** كل رسالة في change مستقل، كما تجمّع ميتا أحياناً */
    private function postMessages(array ...$messages)
    {
        $changes = array_map(fn (array $m) => ['field' => 'messages', 'value' => [
            'messaging_product' => 'whatsapp',
            'metadata' => ['display_phone_number' => '15550783881', 'phone_number_id' => self::TEST_PHONE_NUMBER_ID],
            'contacts' => [['profile' => ['name' => 'عميل'], 'wa_id' => $m['from']]],
            'messages' => [$m],
        ]], $messages);

        return $this->postRawWebhook(json_encode(['object' => 'whatsapp_business_account', 'entry' => [['id' => '102290129340398', 'changes' => $changes]]]));
    }

    private function databaseError(string $message = 'database or disk is full'): QueryException
    {
        return new QueryException('sqlite', 'insert into "wa_messages"', [], new \PDOException($message));
    }

    public function test_a_database_failure_stores_nothing_and_asks_meta_to_retry(): void
    {
        $this->configureChannel(['reliability' => ['webhook_5xx' => true]]);
        $this->failOn['wamid.A'] = $this->databaseError();

        $this->postMessages($this->message('wamid.A', self::ALICE))->assertStatus(503)->assertExactJson(['ok' => false]);

        // لا محادثة نصف مكتوبة بلا رسالتها
        $this->assertSame(0, WaConversation::count());
        $this->assertSame(0, WaMessage::count());
        Queue::assertNothingPushed();

        // إعادة ميتا بعد زوال العطل تخزّن وتمرّر
        $this->failOn = [];
        $this->postMessages($this->message('wamid.A', self::ALICE))->assertOk();
        $this->assertSame(1, WaMessage::count());
        Queue::assertPushed(ProcessWhatsAppReply::class, 1);
    }

    public function test_a_locked_database_also_asks_meta_to_retry(): void
    {
        // داخل معاملة الاختبار يحوّله Laravel إلى DeadlockException (PDOException) لتعيد المعاملة الخارجية
        $this->configureChannel(['reliability' => ['webhook_5xx' => true]]);
        $this->failOn['wamid.A'] = $this->databaseError('database is locked');

        $this->postMessages($this->message('wamid.A', self::ALICE))->assertStatus(503);
        Queue::assertNothingPushed();
    }

    public function test_without_the_flag_a_database_failure_still_answers_200(): void
    {
        $this->failOn['wamid.A'] = $this->databaseError();

        $this->postMessages($this->message('wamid.A', self::ALICE))->assertOk()->assertExactJson(['ok' => true]);
        $this->assertSame(0, WaConversation::count());
    }

    public function test_other_errors_answer_200_even_with_the_flag(): void
    {
        $this->configureChannel(['reliability' => ['webhook_5xx' => true]]);
        $this->failOn['wamid.A'] = new \RuntimeException('bad payload');

        $this->postMessages($this->message('wamid.A', self::ALICE))->assertOk();
    }

    public function test_messages_stored_before_a_later_failure_in_the_payload_are_still_scheduled(): void
    {
        $this->failOn['wamid.B'] = new \RuntimeException('bad payload');

        $this->postMessages($this->message('wamid.A', self::ALICE), $this->message('wamid.B', self::BOB))->assertOk();

        $alice = WaConversation::where('phone', self::ALICE)->sole();
        Queue::assertPushed(ProcessWhatsAppReply::class, 1);
        Queue::assertPushed(ProcessWhatsAppReply::class, fn ($job) => $job->conversationId === $alice->id);
        $this->assertFalse(WaConversation::where('phone', self::BOB)->exists());
    }

    public function test_a_retry_of_a_message_stored_but_never_scheduled_schedules_it(): void
    {
        $conv = WaConversation::factory()->create(['phone' => self::ALICE, 'wa_phone' => self::ALICE, 'last_inbound_at' => now()]);
        WaMessage::factory()->inbound()->create(['conversation_id' => $conv->id, 'wamid' => 'wamid.A']);

        $this->postMessages($this->message('wamid.A', self::ALICE))->assertOk();

        $this->assertSame(1, WaMessage::count());
        Queue::assertPushed(ProcessWhatsAppReply::class, fn ($job) => $job->conversationId === $conv->id);
    }

    public function test_the_opt_out_notice_is_sent_after_the_rows_are_committed(): void
    {
        $base = DB::transactionLevel();   // RefreshDatabase يلف الاختبار بمعاملة
        $levels = [];
        Http::fake(['graph.facebook.com/*' => function () use (&$levels) {
            $levels[] = DB::transactionLevel();

            return Http::response(['messages' => [['id' => 'wamid.OUT.1']]]);
        }]);

        $this->postMessages($this->message('wamid.A', self::ALICE, 'ايقاف'))->assertOk();

        $this->assertSame([$base], $levels);
        Queue::assertNothingPushed();
    }
}
