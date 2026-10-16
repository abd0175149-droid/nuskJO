<?php

namespace Tests\Feature\WhatsApp;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\WaMessage;
use App\Services\WhatsApp\Search\SearchAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Concerns\BuildsWhatsAppStaff;
use Tests\TestCase;

/**
 * حدود البحث وتدقيقه (E30.4): 30 طلباً في الدقيقة لكل موظف بعدّاد خاص بالبحث، وصف تدقيق واحد كل 10 دقائق
 * بلا نص الاستعلام (بصمة وطول وأسماء الفلاتر)، وسطر warn للبحث الكثيف المستمر.
 */
class SearchThrottleAndAuditTest extends TestCase
{
    use BuildsWhatsAppStaff;
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        config(['features.env.message_search' => true]);
        $this->manager = $this->staffWith('manager', ['whatsapp.view', 'whatsapp.view_all', 'whatsapp.media']);
        WaMessage::factory()->create(['conversation_id' => $this->conversationTagged()->id, 'body' => 'جواز السفر رقم N1234567']);
    }

    private function search(string $q = 'جواز', array $more = [])
    {
        return $this->actingAs($this->manager)->getJson('/api/whatsapp/search?'.http_build_query(['q' => $q] + $more));
    }

    public function test_the_thirty_first_search_in_a_minute_is_throttled_with_its_own_counter(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->search()->assertOk();
        }
        $this->search()->assertStatus(429);

        // عدّاد البحث بمفتاحه (wa-search): throttle:X,1 بلا بادئة يجمع كل مسارات المستخدم في عدّاد واحد
        $user = sha1((string) $this->manager->id);
        $this->assertSame(30, RateLimiter::attempts('wa-search'.$user));
        $this->assertSame(0, RateLimiter::attempts($user));

        $this->travel(61)->seconds();
        $this->search()->assertOk();
    }

    public function test_one_sampled_row_per_ten_minutes_without_the_query_text(): void
    {
        // نافذة العيّنات 10 دقائق ثابتة البداية: الاختبار يبدأ في أولها
        $this->travelTo(now()->startOfHour()->addMinute());
        $this->search('جواز السفر', ['direction' => 'in', 'source' => 'customer'])->assertOk();
        $this->search('N1234567')->assertOk();
        $this->search('جواز')->assertOk();

        $row = AuditLog::where('action', AuditAction::WaMessageSearch->value)->sole();
        $this->assertSame('info', $row->severity);
        $this->assertSame($this->manager->id, $row->user_id);
        $this->assertSame(['q_len', 'q_fp', 'filters', 'hits', 'capped', 'engine'], array_keys($row->changes));
        $this->assertSame(['direction', 'source'], $row->changes['filters']);
        $this->assertSame(10, $row->changes['q_len']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $row->changes['q_fp']);
        $this->assertStringNotContainsString('جواز', json_encode($row->getAttributes(), JSON_UNESCAPED_UNICODE));

        $this->travel(11)->minutes();
        $this->search('N1234567')->assertOk();
        $rows = AuditLog::where('action', AuditAction::WaMessageSearch->value)->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame(2, $rows[1]->changes['suppressed_count']);
        $this->assertStringNotContainsString('1234567', json_encode($rows[1]->getAttributes()));
    }

    public function test_sustained_heavy_searching_writes_one_warning(): void
    {
        $result = ['q_len' => 5, 'q_fp' => 'abcdefabcdef', 'total' => 1, 'total_capped' => false, 'engine' => 'fts'];
        $this->freezeTime();
        foreach (range(1, 3) as $minute) {
            foreach (range(1, 20) as $i) {
                SearchAudit::record($this->manager, $result, []);
            }
            $minute < 3 && $this->travel(60)->seconds();
        }
        SearchAudit::record($this->manager, $result, []);

        $warn = AuditLog::where('action', AuditAction::WaMessageSearch->value)->where('severity', 'warn')->sole();
        $this->assertTrue($warn->changes['burst']);
        $this->assertSame(20, $warn->changes['per_minute']);
    }

    public function test_bursts_spread_over_time_do_not_warn(): void
    {
        $result = ['q_len' => 5, 'q_fp' => 'abcdefabcdef', 'total' => 1, 'total_capped' => false, 'engine' => 'fts'];
        $this->freezeTime();
        foreach ([20, 5, 20] as $n) {
            foreach (range(1, $n) as $i) {
                SearchAudit::record($this->manager, $result, []);
            }
            $this->travel(60)->seconds();
        }

        $this->assertSame(0, AuditLog::where('severity', 'warn')->count());
    }
}
