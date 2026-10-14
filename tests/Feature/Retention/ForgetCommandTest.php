<?php

namespace Tests\Feature\Retention;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Notification;
use App\Models\QuoteRequest;
use App\Models\User;
use App\Models\WaAdminAction;
use App\Models\WaAdminNumber;
use App\Models\WaBotUsage;
use App\Models\WaConversation;
use App\Models\WaCustomerNote;
use App\Models\WaErasureRequest;
use App\Models\WaMessage;
use App\Models\WaOptout;
use App\Support\Retention;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * whatsapp:forget (E13.5): المعاينة لا تغيّر شيئاً، والرمز الخاطئ يُرفض، والتنفيذ يحذف كل ما يحمل الشخص
 * ويُبقي المالي و«إيقاف» وصف المحادثة شاهداً، ويحذف الوسائط الحديثة من ميتا، على دفعات قابلة للاستئناف.
 */
class ForgetCommandTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    private const PHONE = '16505551234';

    private WaConversation $conv;

    protected function setUp(): void
    {
        parent::setUp();
        config(['retention.erasure_ledger' => storage_path('framework/testing/erasure-ledger.jsonl'),
            'retention.shrink_ledger' => storage_path('framework/testing/shrink.json'), 'retention.batch_sleep_ms' => 0]);
        File::delete([config('retention.erasure_ledger'), config('retention.shrink_ledger')]);
        $this->configureChannel();
        $this->conv = WaConversation::factory()->windowOpen()->create(['phone' => self::PHONE, 'wa_phone' => self::PHONE, 'wa_profile_name' => 'سامي']);
    }

    protected function tearDown(): void
    {
        File::delete([config('retention.erasure_ledger'), config('retention.shrink_ledger')]);
        parent::tearDown();
    }

    private function seedConversation(): array
    {
        $user = User::factory()->create();
        WaMessage::factory()->count(3)->inbound()->create(['conversation_id' => $this->conv->id]);
        WaMessage::factory()->inbound()->create(['conversation_id' => $this->conv->id, 'msg_type' => 'image', 'media_id' => 'media.NEW', 'body' => '📷 صورة']);
        WaMessage::factory()->inbound()->create(['conversation_id' => $this->conv->id, 'msg_type' => 'image', 'media_id' => 'media.OLD', 'body' => '📷 صورة', 'created_at' => now()->subDays(20)]);
        WaCustomerNote::create(['conversation_id' => $this->conv->id, 'note' => 'يريد عمرة', 'source' => 'staff', 'created_at' => now()]);
        DB::table('wa_conversation_topics')->insert(['conversation_id' => $this->conv->id, 'topic' => 'umrah', 'source' => 'manual', 'created_at' => now(), 'updated_at' => now()]);
        $loose = QuoteRequest::create(['request_number' => 'Q-LOOSE', 'conversation_id' => $this->conv->id, 'phone' => self::PHONE, 'type' => 'flight', 'pax_adults' => 1, 'status' => 'new']);
        $invoiced = QuoteRequest::create(['request_number' => 'Q-PAID', 'conversation_id' => $this->conv->id, 'phone' => self::PHONE, 'type' => 'package', 'pax_adults' => 1, 'status' => 'won']);
        DB::table('invoices')->insert(['invoice_number' => 'INV-1', 'quote_request_id' => $invoiced->id, 'client_id' => Client::factory()->create()->id, 'exchange_rate_snapshot' => 0.189, 'invoice_date' => now()->toDateString(),
            'trip_date' => now()->toDateString(), 'status' => 'approved', 'total_sell_jod' => 100, 'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
        Notification::create(['user_id' => $user->id, 'title' => 'تحويل', 'body' => 'محادثة', 'type' => 'whatsapp', 'action_url' => "/whatsapp/inbox?conv={$this->conv->id}"]);
        Notification::create(['user_id' => $user->id, 'title' => 'طلب Q-LOOSE', 'body' => 'طلب Q-LOOSE جديد', 'type' => 'whatsapp']);
        Notification::create(['user_id' => $user->id, 'title' => 'غيره', 'body' => 'لا علاقة', 'type' => 'whatsapp', 'action_url' => '/whatsapp/inbox?conv=99999']);
        $admin = WaAdminNumber::factory()->create();
        WaAdminAction::create(['admin_number_id' => $admin->id, 'tool' => 'find_client', 'action' => 'search', 'status' => 'done', 'input' => ['query' => self::PHONE], 'created_at' => now()]);
        WaBotUsage::create(['conversation_id' => $this->conv->id, 'source' => 'live', 'model' => 'm', 'calls' => 1, 'prompt_tokens' => 10, 'output_tokens' => 5,
            'cache_read_tokens' => 0, 'cache_write_tokens' => 0, 'tools_used' => [], 'leaked' => false, 'latency_ms' => 1, 'created_at' => now()]);
        WaOptout::create(['phone' => self::PHONE, 'reason' => 'طلب العميل', 'created_at' => now()]);
        Cache::put("wa:debounce:{$this->conv->id}", 'tok', 60);

        return [$loose, $invoiced];
    }

    private function token(array $args = []): string
    {
        Artisan::call('whatsapp:forget', ['--conversation' => $this->conv->id] + $args);
        preg_match('/--token=([A-Z0-9]{6})/', Artisan::output(), $m);

        return $m[1];
    }

    public function test_the_preview_changes_nothing_and_a_wrong_token_is_refused(): void
    {
        $this->fakeGraph();
        $this->seedConversation();
        $before = DB::table('wa_messages')->count();

        $this->assertSame(6, strlen($this->token()) + 0);
        $this->assertSame(1, Artisan::call('whatsapp:forget', ['--conversation' => $this->conv->id, '--token' => 'ZZZZZZ']));

        $this->assertSame($before, DB::table('wa_messages')->count());
        $this->assertSame(self::PHONE, $this->conv->fresh()->phone);
        Http::assertNothingSent();
    }

    public function test_execution_erases_the_person_and_keeps_what_finance_and_opt_out_need(): void
    {
        $this->fakeGraph();
        [$loose, $invoiced] = $this->seedConversation();
        $token = $this->token();

        $this->assertSame(0, Artisan::call('whatsapp:forget', ['--conversation' => $this->conv->id, '--token' => $token]));

        $this->assertSame(0, WaMessage::where('conversation_id', $this->conv->id)->count());
        $this->assertSame(0, WaCustomerNote::count());
        $this->assertSame(0, DB::table('wa_conversation_topics')->count());
        $this->assertNull(QuoteRequest::find($loose->id));
        $this->assertNotNull(QuoteRequest::find($invoiced->id), 'طلب مرتبط بفاتورة يبقى');
        $this->assertSame(['غيره'], Notification::pluck('title')->all());
        $this->assertSame(['redacted' => 'erasure'], WaAdminAction::sole()->input);
        $this->assertNull(WaBotUsage::sole()->conversation_id);
        $this->assertSame(1, WaOptout::count(), '«إيقاف» يبقى');
        $this->assertNull(Cache::get("wa:debounce:{$this->conv->id}"));

        $c = $this->conv->fresh();
        $this->assertSame(['erased', null, null, null, null, false], [$c->status, $c->phone, $c->wa_phone, $c->wa_profile_name, $c->last_message_preview, $c->bot_enabled]);
        $this->assertNotNull($c->erased_at);

        $request = WaErasureRequest::sole();
        $this->assertSame(['done', 'staff', Retention::subjectHmac(self::PHONE)], [$request->status, $request->channel, $request->subject_hmac]);
        $audit = AuditLog::where('action', 'wa.data_erase')->sole();
        $this->assertStringNotContainsString(self::PHONE, json_encode($audit->getAttributes(), JSON_UNESCAPED_UNICODE));
        $this->assertSame(1, $audit->changes['kept_invoiced_quotes']);
        $this->assertSame(Retention::subjectHmac(self::PHONE), Retention::erasureLedger()[0]['subject_hmac']);
        $this->assertStringNotContainsString(self::PHONE, File::get(config('retention.erasure_ledger')));
        $this->assertSame(5, Retention::shrinkAllowance()['wa_messages']);
    }

    public function test_only_recent_media_is_deleted_at_meta_and_failures_do_not_stop_it(): void
    {
        $this->seedConversation();
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'x']], 500)]);
        $token = $this->token();

        Artisan::call('whatsapp:forget', ['--conversation' => $this->conv->id, '--token' => $token]);

        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_contains($r->url(), 'media.NEW'));
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'media.OLD'));
        $this->assertSame('erased', $this->conv->fresh()->status);
        $this->assertSame(1, WaErasureRequest::sole()->counts['media_meta_failed']);
    }

    public function test_a_request_must_be_verified_and_match_the_number(): void
    {
        $request = WaErasureRequest::create(['status' => 'received', 'channel' => 'whatsapp', 'conversation_id' => $this->conv->id,
            'subject_hmac' => Retention::subjectHmac(self::PHONE), 'received_at' => now(), 'due_at' => now()->addDays(30)]);

        $this->assertSame(1, Artisan::call('whatsapp:forget', ['--request' => $request->id]));
        $this->assertStringContainsString('تحقّقت من الهوية', Artisan::output());

        $request->update(['status' => 'verified']);
        $this->assertSame(1, Artisan::call('whatsapp:forget', ['phone' => '15550783881', '--request' => $request->id]));
        $this->assertSame(0, Artisan::call('whatsapp:forget', ['phone' => self::PHONE, '--request' => $request->id]));
    }

    public function test_admin_chats_and_already_erased_conversations_are_refused(): void
    {
        WaAdminNumber::factory()->create(['phone' => self::PHONE]);
        $this->assertSame(1, Artisan::call('whatsapp:forget', ['--conversation' => $this->conv->id]));
        $this->assertStringContainsString('--include-admin', Artisan::output());

        $this->conv->update(['status' => 'erased']);
        $this->assertSame(1, Artisan::call('whatsapp:forget', ['--conversation' => $this->conv->id, '--include-admin' => true]));
    }

    public function test_it_works_in_batches_and_resumes_after_an_interruption(): void
    {
        config(['retention.batch_size' => 500]);
        $rows = [];
        foreach (range(1, 1200) as $i) {
            $rows[] = ['conversation_id' => $this->conv->id, 'wamid' => "wamid.B{$i}", 'direction' => 'in', 'source' => 'customer', 'msg_type' => 'text',
                'body' => 'x', 'created_at' => now(), 'updated_at' => now()];
        }
        foreach (array_chunk($rows, 300) as $chunk) {
            DB::table('wa_messages')->insert($chunk);
        }
        $token = $this->token();

        // انقطاع في منتصف التنفيذ (الدفعة الثانية): ما حُذف يبقى محذوفاً، والطلب running
        $calls = 0;
        DB::listen(function ($q) use (&$calls) {
            if (str_starts_with($q->sql, 'delete from "wa_messages"') && ++$calls === 2) {
                throw new \RuntimeException('انقطاع مصطنع');
            }
        });
        try {
            Artisan::call('whatsapp:forget', ['--conversation' => $this->conv->id, '--token' => $token]);
        } catch (\RuntimeException) {
        }
        $this->assertSame(700, WaMessage::count());
        $this->assertSame('running', WaErasureRequest::sole()->status);

        app('db')->connection()->flushQueryLog();
        app('events')->forget(QueryExecuted::class);
        $this->assertSame(0, Artisan::call('whatsapp:forget', ['--conversation' => $this->conv->id, '--token' => $this->token()]));
        $this->assertSame(0, WaMessage::count());
        $this->assertSame('done', WaErasureRequest::sole()->status);
    }

    public function test_the_client_card_is_erased_only_without_financial_records(): void
    {
        $client = Client::factory()->create(['phone' => '+16505551234', 'email' => 'x@example.test']);
        $this->conv->update(['client_id' => $client->id]);

        Artisan::call('whatsapp:forget', ['--conversation' => $this->conv->id, '--client' => true, '--token' => $this->token(['--client' => true])]);

        $erased = Client::withTrashed()->find($client->id);
        $this->assertSame(["عميل محذوف #{$client->id}", null, null], [$erased->name, $erased->phone, $erased->email]);
        $this->assertTrue($erased->trashed());
    }

    public function test_a_client_with_an_invoice_keeps_the_card(): void
    {
        $client = Client::factory()->create(['phone' => '+16505551234']);
        DB::table('invoices')->insert(['invoice_number' => 'INV-9', 'client_id' => $client->id, 'exchange_rate_snapshot' => 0.189, 'invoice_date' => now()->toDateString(),
            'trip_date' => now()->toDateString(), 'status' => 'approved', 'total_sell_jod' => 10, 'created_by' => User::factory()->create()->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->conv->update(['client_id' => $client->id]);

        Artisan::call('whatsapp:forget', ['--conversation' => $this->conv->id, '--client' => true]);
        $this->assertStringContainsString('لا تُمحى', Artisan::output());
        Artisan::call('whatsapp:forget', ['--conversation' => $this->conv->id, '--client' => true, '--token' => $this->token(['--client' => true])]);

        $this->assertSame($client->name, $client->fresh()->name);
        $this->assertSame('erased', $this->conv->fresh()->status);
    }

    public function test_reapply_after_a_restore_erases_the_returned_conversation_again(): void
    {
        $this->fakeGraph();
        $this->seedConversation();
        Artisan::call('whatsapp:forget', ['--conversation' => $this->conv->id, '--token' => $this->token()]);

        // «استعادة»: المحادثة رجعت برقمها ورسائلها، وسجل المحو خارج القاعدة بقي
        $back = WaConversation::factory()->create(['phone' => self::PHONE, 'wa_phone' => self::PHONE]);
        WaMessage::factory()->count(2)->inbound()->create(['conversation_id' => $back->id]);

        Artisan::call('whatsapp:erasure-reapply', ['--dry-run' => true]);
        $this->assertSame(2, WaMessage::where('conversation_id', $back->id)->count());
        $this->assertSame(0, Artisan::call('whatsapp:erasure-reapply'));

        $this->assertSame(0, WaMessage::where('conversation_id', $back->id)->count());
        $this->assertSame('erased', $back->fresh()->status);
        $this->assertSame(1, Http::recorded(fn (Request $r) => $r->method() === 'DELETE')->count(), 'إعادة التطبيق بلا نداءات لميتا');
    }
}
