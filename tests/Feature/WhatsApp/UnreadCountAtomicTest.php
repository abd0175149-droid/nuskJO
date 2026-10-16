<?php

namespace Tests\Feature\WhatsApp;

use App\Models\WaConversation;
use App\Models\WaMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\Concerns\SignsMetaWebhooks;
use Tests\TestCase;

/**
 * عدّاد غير المقروء (E21 T4؛ الزيادة الذرية موجودة منذ E07.6): UPDATE ... SET unread_count = unread_count + 1، فطلبان
 * متزامنان لا يضيّعان رسالة ولو قرأ أحدهما المحادثة قبل أن يكتب الآخر.
 */
class UnreadCountAtomicTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;
    use SignsMetaWebhooks;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->configureChannel();
        $this->travelTo(Carbon::createFromTimestamp(1760425800 + 60));
    }

    public function test_a_write_between_loading_the_conversation_and_counting_is_not_lost(): void
    {
        $conv = WaConversation::factory()->create(['phone' => '16505551234', 'wa_phone' => '16505551234', 'unread_count' => 5]);
        // طلب آخر يكتب بعد أن حمّل هذا الطلب المحادثة وقبل أن يزيد العدّاد
        WaMessage::creating(function () use ($conv) {
            DB::table('wa_conversations')->where('id', $conv->id)->update(['unread_count' => 7]);
        });

        $this->postMetaWebhook('text.json')->assertOk();

        $this->assertSame(8, (int) $conv->fresh()->unread_count, '7 + 1، لا 5 + 1');
    }

    public function test_the_increment_is_one_statement(): void
    {
        $conv = WaConversation::factory()->create(['phone' => '16505551234', 'wa_phone' => '16505551234', 'unread_count' => 2]);
        $updates = [];
        DB::listen(function ($q) use (&$updates) {
            if (str_contains($q->sql, 'unread_count')) {
                $updates[] = $q->sql;
            }
        });

        $this->postMetaWebhook('text.json')->assertOk();

        $this->assertSame(3, (int) $conv->fresh()->unread_count);
        $this->assertNotEmpty(array_filter($updates, fn ($sql) => preg_match('/"unread_count"\s*=\s*unread_count \+ 1/', $sql)));
    }
}
