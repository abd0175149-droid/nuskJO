<?php

namespace Tests\Feature\WhatsApp;

use App\Models\Offer;
use App\Models\QuoteRequest;
use App\Models\User;
use App\Models\WaConversation;
use App\Models\WaConversationEntry;
use App\Models\WaQrLink;
use App\Services\WhatsApp\BotAnalytics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «مصادر المحادثات» (E38.8): محادثات مميزة لكل رمز وعرض وإعلان، وطلبات تسعير أُنشئت للمحادثة نفسها بعد صف الدخول،
 * ومنها المحسومة. أعداد فقط بلا أسماء ولا أرقام، مع تنبيه أن القياس داخلي.
 */
class EntriesAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private function quote(WaConversation $c, ?int $offerId, string $status, $at): void
    {
        $q = QuoteRequest::create(['conversation_id' => $c->id, 'offer_id' => $offerId, 'phone' => '16505551234', 'type' => 'package', 'status' => $status]);
        $q->forceFill(['created_at' => $at])->saveQuietly();
    }

    public function test_conversations_quotes_and_won_per_code(): void
    {
        $offer = Offer::factory()->create(['title' => 'عمرة رجب']);
        $link = WaQrLink::create(['offer_id' => $offer->id, 'channel' => 'print', 'label' => 'ملصق', 'prefilled_message' => 'x', 'status' => 'active']);
        $convs = WaConversation::factory()->count(3)->create();
        foreach ($convs as $c) {
            WaConversationEntry::create(['conversation_id' => $c->id, 'offer_id' => $offer->id, 'qr_link_id' => $link->id, 'channel' => 'qr', 'created_at' => now()->subDay()]);
        }
        $this->quote($convs[0], $offer->id, 'won', now());
        // قبل صف الدخول: لا يُحسب
        $this->quote($convs[1], $offer->id, 'new', now()->subDays(3));
        // لعرض آخر: لا يُحسب لهذا الرمز
        $this->quote($convs[2], Offer::factory()->create()->id, 'new', now());

        $out = BotAnalytics::entries(30);

        $this->assertSame(['qr' => 3], $out['channels']);
        $row = collect($out['rows'])->firstWhere('qr_link_id', $link->id);
        $this->assertSame([3, 1, 1, 'عمرة رجب', 'ملصق'], [$row['conversations'], $row['quotes'], $row['won'], $row['offer_title'], $row['qr_label']]);
        $this->assertStringContainsString('قياس داخلي تقريبي', $out['note']);
        $this->assertStringNotContainsString('16505551234', json_encode($out, JSON_UNESCAPED_UNICODE));
    }

    public function test_ads_are_grouped_by_source_and_the_period_filters(): void
    {
        $c = WaConversation::factory()->create();
        WaConversationEntry::create(['conversation_id' => $c->id, 'channel' => 'ad', 'referral_source_type' => 'ad', 'referral_source_id' => '120',
            'referral_headline' => 'عمرة رجب', 'created_at' => now()->subDays(10)]);

        $this->assertSame('عمرة رجب', BotAnalytics::entries(30)['rows'][0]['headline']);
        $this->assertSame([], BotAnalytics::entries(7)['rows']);
        $this->assertSame(30, BotAnalytics::entries(15)['days'], 'فترة غير معروفة = 30');
    }

    public function test_the_analytics_endpoint_carries_entries_behind_the_flag(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->getJson('/api/whatsapp/analytics')->assertOk()->assertJsonPath('entries', null);
        config(['features.env.entry_tracking' => true]);
        $this->actingAs($admin)->getJson('/api/whatsapp/analytics?entries_days=7')->assertOk()->assertJsonPath('entries.days', 7);
    }
}
