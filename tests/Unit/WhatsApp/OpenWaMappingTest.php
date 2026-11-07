<?php

namespace Tests\Unit\WhatsApp;

use App\Models\WaChannel;
use App\Services\WhatsApp\ChoiceResolver;
use App\Services\WhatsApp\OpenWa\InboundParser;
use App\Services\WhatsApp\OpenWa\OpenWaError;
use App\Services\WhatsApp\OpenWa\PayloadMapper;
use App\Services\WhatsApp\Outbound\InteractiveTypes;
use App\Services\WhatsApp\Outbound\OutboundMessage;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Response;
use Tests\TestCase;

/** تحويل الحمولات بين الشكل الداخلي وOpenWA، وتصنيف أخطاء البوابة */
class OpenWaMappingTest extends TestCase
{
    public function test_text_with_quote_maps_to_send_text(): void
    {
        $p = OutboundMessage::text('مرحبا')->payload('false_1@c.us_X');
        [$path, $body] = PayloadMapper::map($p, '962791112233@c.us');
        $this->assertSame('/messages/send-text', $path);
        $this->assertSame(['chatId' => '962791112233@c.us', 'text' => 'مرحبا', 'linkPreview' => false, 'quotedMessageId' => 'false_1@c.us_X'], $body);
    }

    public function test_buttons_become_numbered_text_and_options_keep_order(): void
    {
        $m = InteractiveTypes::buttons('اختر', [['id' => 'ch:AAAAAAAAAAAAAAAA:r1', 'title' => 'عمرة'], ['id' => 'ch:AAAAAAAAAAAAAAAA:r2', 'title' => 'حج']]);
        [$path, $body] = PayloadMapper::map($m->payload(), 'x@c.us');
        $this->assertSame('/messages/send-text', $path);
        $this->assertStringContainsString("1. عمرة\n2. حج", $body['text']);
        $this->assertSame(['ch:AAAAAAAAAAAAAAAA:r1', 'ch:AAAAAAAAAAAAAAAA:r2'], array_column(PayloadMapper::options($m->payload()['interactive']), 'id'));
    }

    public function test_reaction_maps_to_react(): void
    {
        [$path, $body] = PayloadMapper::map(['type' => 'reaction', 'reaction' => ['message_id' => 'M1', 'emoji' => '👍']], 'x@c.us');
        $this->assertSame('/messages/react', $path);
        $this->assertSame(['chatId' => 'x@c.us', 'messageId' => 'M1', 'emoji' => '👍'], $body);
    }

    public function test_parser_maps_types_reads_groups_and_drops_statuses(): void
    {
        $ch = new WaChannel(['phone' => '962790000001']);
        $ch->id = 5;
        $in = InboundParser::parse(['id' => 'A', 'chatId' => '962791112233@c.us', 'from' => '962791112233@c.us', 'type' => 'voice', 'body' => str_repeat('A', 300),
            'media' => ['mimetype' => 'audio/ogg; codecs=opus'], 'timestamp' => 1700000000], $ch);
        $this->assertSame('audio', $in->type);
        $this->assertTrue($in->voice);
        $this->assertSame('🎤 رسالة صوتية', $in->body);
        $this->assertSame('A', $in->media['id']);
        $this->assertSame(5, $in->channelId);
        // المجموعة تُقرأ بهويتها وكاتبها (GroupChats)، لا بهاتف الكاتب
        $g = InboundParser::parse(['id' => 'B', 'chatId' => '120363@g.us', 'from' => '120363@g.us', 'author' => '962791112233@c.us', 'type' => 'text', 'body' => 'x',
            'isGroup' => true, 'senderPhone' => '962791112233', 'contact' => ['pushName' => 'أبو خالد']], $ch);
        $this->assertSame(['120363@g.us', null, null, 'أبو خالد', '962791112233'], [$g->groupJid, $g->fromPhone, $g->lid, $g->author, $g->authorPhone]);
        $this->assertNull(InboundParser::parse(['id' => 'S', 'chatId' => 'status@broadcast', 'type' => 'text', 'body' => 'x'], $ch));
        $this->assertNull(InboundParser::parse(['id' => 'C', 'chatId' => '962791112233@c.us', 'type' => 'e2e_notification'], $ch));
    }

    public function test_gateway_errors_are_classified(): void
    {
        $r = fn (int $status, array $json, array $headers = []) => new Response(new PsrResponse($status, ['Content-Type' => 'application/json'] + $headers, json_encode($json)));
        $this->assertSame('CHANNEL_NOT_READY', ($e = OpenWaError::fromResponse($r(503, ['message' => 'Session not ready'])))->code);
        $this->assertTrue($e->notSent);
        $this->assertSame('GW_RATE', ($e = OpenWaError::fromResponse($r(429, ['message' => 'Too many'], ['Retry-After' => '12'])))->code);
        $this->assertSame(12, $e->retryAfter);
        $this->assertSame('GW_AUTH', OpenWaError::fromResponse($r(401, ['message' => 'Invalid API key']))->code);
        $this->assertSame('QUOTE_NOT_FOUND', OpenWaError::fromResponse($r(400, ['message' => 'Quoted message not found']))->code);
        $this->assertFalse(OpenWaError::fromResponse($r(500, ['message' => 'engine crashed']))->notSent);
        $this->assertSame('IDEMPOTENCY_IN_PROGRESS', OpenWaError::fromResponse($r(409, ['code' => 'IDEMPOTENCY_KEY_IN_PROGRESS']))->code);
    }

    public function test_choice_pattern_is_unchanged(): void
    {
        $this->assertMatchesRegularExpression(ChoiceResolver::PATTERN, 'ch:AAAAAAAAAAAAAAAA:r1');
    }
}
