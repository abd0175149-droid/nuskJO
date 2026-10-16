<?php

namespace Tests\Feature\WhatsApp;

use App\Jobs\ProcessWhatsAppReply;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\ReactionProjector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\Concerns\SignsMetaWebhooks;
use Tests\TestCase;

/**
 * تفاعل العميل على رسالته الأصلية (E33، بلا علم لأنه تصحيح خلل): شارة في payload.reactions.customer، والإزالة تمحوها،
 * وإعادة متأخرة من ميتا بطابع أقدم لا تعيد ما أُزيل، ولا '👍' افتراضي. صف التفاعل يبقى، والتفاعل لا يغيّر النافذة ولا
 * العدّاد ولا المعاينة ولا يوقظ البوت.
 */
class ReactionInboundTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;
    use SignsMetaWebhooks;

    private const TARGET = 'wamid.HBgLMTY1MDU1NTEyMzQVAgARGBI0MkZERjYwREJFODBFQTIxNDEA';

    private WaConversation $conv;

    private WaMessage $target;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureChannel();
        $this->travelTo(Carbon::createFromTimestamp(1760425600, config('app.timezone')));
        $this->conv = WaConversation::factory()->create([
            'phone' => '16505551234', 'wa_phone' => '16505551234',
            'last_inbound_at' => now()->subHour(), 'last_message_preview' => 'عرض عمرة رمضان', 'unread_count' => 2,
        ]);
        $this->target = WaMessage::factory()->outbound()->create(['conversation_id' => $this->conv->id, 'wamid' => self::TARGET, 'body' => 'عرض عمرة رمضان']);
    }

    private function state(): array
    {
        $c = $this->conv->fresh();

        return [$c->unread_count, (string) $c->last_inbound_at, $c->last_message_preview, $c->needs_attention];
    }

    public function test_an_emoji_lands_on_the_original_message_and_its_body_is_untouched(): void
    {
        Queue::fake();
        $before = $this->state();

        $this->postMetaWebhook('reaction.json')->assertOk();

        $target = $this->target->fresh();
        $this->assertSame('🙏', data_get($target->payload, 'reactions.customer.emoji'));
        $this->assertSame(1760425440, data_get($target->payload, 'reactions.customer.ts'));
        $this->assertSame('عرض عمرة رمضان', $target->body);
        $row = WaMessage::where('msg_type', 'reaction')->sole();
        $this->assertSame([$row->id, '🙏'], [data_get($target->payload, 'reactions.customer.reaction_message_id'), $row->body]);
        $this->assertSame($before, $this->state(), 'لا نافذة ولا عدّاد ولا معاينة');
        Queue::assertNotPushed(ProcessWhatsAppReply::class);
    }

    public function test_removal_clears_the_badge_and_no_default_thumbs_up_is_stored(): void
    {
        $this->postMetaWebhook('reaction.json')->assertOk();
        $this->postMetaWebhook('reaction_removed.json')->assertOk();

        $target = $this->target->fresh();
        $this->assertNull(data_get($target->payload, 'reactions.customer.emoji'));
        $this->assertSame([], ReactionProjector::forThread($target->payload));
        $this->assertSame(['🙏', '↩️ أزال التفاعل'], WaMessage::where('msg_type', 'reaction')->orderBy('id')->pluck('body')->all());
        $this->assertSame(0, WaMessage::where('body', '👍')->count());
    }

    public function test_a_late_retry_with_an_older_timestamp_does_not_bring_back_a_removed_reaction(): void
    {
        $this->postMetaWebhook('reaction_removed.json')->assertOk();
        $this->postMetaWebhook('reaction.json')->assertOk();

        $this->assertNull(data_get($this->target->fresh()->payload, 'reactions.customer.emoji'));
        $this->assertSame(1760425560, data_get($this->target->fresh()->payload, 'reactions.customer.ts'));
    }

    public function test_an_unknown_target_leaves_an_orphan_row(): void
    {
        $this->target->delete();

        $this->postMetaWebhook('reaction.json')->assertOk();

        $row = WaMessage::where('msg_type', 'reaction')->sole();
        $this->assertSame([self::TARGET, '🙏'], [$row->payload['_reaction_to'], $row->body]);
    }

    public function test_a_target_in_another_conversation_is_never_touched(): void
    {
        $this->target->update(['conversation_id' => WaConversation::factory()->create()->id]);

        $this->postMetaWebhook('reaction.json')->assertOk();

        $this->assertNull(data_get($this->target->fresh()->payload, 'reactions'));
    }
}
