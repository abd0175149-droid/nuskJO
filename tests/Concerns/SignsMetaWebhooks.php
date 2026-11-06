<?php

namespace Tests\Concerns;

use App\Models\WaChannel;
use Illuminate\Testing\TestResponse;

/**
 * حمولات ميتا القديمة في tests/Fixtures/whatsapp تُحوَّل إلى أحداث OpenWA وتُرسل موقّعة إلى webhook الرقم (2026-11-06)،
 * فتبقى اختبارات الوارد والبوت والهوية تعمل على المسار الجديد كما هو:
 *   messages[]  ← message.received (والتفاعل ← message.reaction)
 *   statuses[]  ← message.ack أو message.failed
 *   smb_message_echoes ← message.sent (fromMe)
 * التوقيع بسر الرقم إن مُرر السر الاختباري القديم، وإلا بالسر المُمرر نفسه (اختبار توقيع خاطئ يبقى 403).
 * أحداث حساب ميتا وحقولها الأخرى لا مقابل لها فتُتجاهل.
 */
trait SignsMetaWebhooks
{
    use FakesOpenWa;

    protected function fixturePath(string $fixture): string
    {
        return base_path('tests/Fixtures/whatsapp/'.$fixture);
    }

    protected function postMetaWebhook(string $fixture, ?string $secret = 'test-app-secret', ?string $signature = null): TestResponse
    {
        return $this->postRawWebhook((string) file_get_contents($this->fixturePath($fixture)), $secret, $signature);
    }

    protected function postRawWebhook(string $raw, ?string $secret = 'test-app-secret', ?string $signature = null): TestResponse
    {
        $channel = WaChannel::query()->orderBy('id')->first() ?? $this->makeChannel();
        $useSecret = $secret === 'test-app-secret' ? self::TEST_WEBHOOK_SECRET : $secret;
        $payload = json_decode($raw, true);
        $events = is_array($payload) ? $this->metaToOpenWa($payload) : [];
        if ($events === []) {
            // معطوب أو بلا ما يقابله: يُرسل كما هو موقّعاً (المتحكم يرده 200 بلا معالجة)
            return $this->postOpenWaRaw($raw, $channel, $signature !== null ? null : $useSecret);
        }
        $last = null;
        foreach ($events as [$event, $data]) {
            $body = json_encode(['event' => $event, 'timestamp' => now()->toIso8601String(), 'sessionId' => $channel->openwa_session_id,
                'idempotencyKey' => hash('sha256', $event.json_encode($data)), 'deliveryId' => bin2hex(random_bytes(4)), 'data' => $data],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $last = $signature !== null
                ? $this->call('POST', '/api/openwa/webhook/'.$channel->uuid, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_OPENWA_SIGNATURE' => $signature], $body)
                : $this->postOpenWaRaw($body, $channel, $useSecret);
        }

        return $last;
    }

    /** @return list<array{0: string, 1: array}> */
    protected function metaToOpenWa(array $payload): array
    {
        $out = [];
        foreach ((array) ($payload['entry'] ?? []) as $entry) {
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                $value = (array) ($change['value'] ?? []);
                $names = [];
                foreach ((array) ($value['contacts'] ?? []) as $c) {
                    $names[(string) ($c['wa_id'] ?? '')] = $c['profile']['name'] ?? null;
                }
                foreach ((array) ($value['messages'] ?? []) as $m) {
                    $out[] = $this->metaMessage((array) $m, $names);
                }
                foreach ((array) ($value['message_echoes'] ?? []) as $m) {
                    $e = $this->metaMessage((array) $m + ['from' => $m['to'] ?? ''], []);
                    $e[1]['fromMe'] = true;
                    $out[] = ['message.sent', $e[1]];
                }
                foreach ((array) ($value['statuses'] ?? []) as $st) {
                    $failed = ($st['status'] ?? '') === 'failed';
                    $out[] = [$failed ? 'message.failed' : 'message.ack', array_filter([
                        'id' => $st['id'] ?? null, 'messageId' => $st['id'] ?? null, 'status' => $st['status'] ?? null,
                        'timestamp' => isset($st['timestamp']) ? (int) $st['timestamp'] : null,
                        'chatId' => isset($st['recipient_id']) ? $st['recipient_id'].'@c.us' : null,
                    ], fn ($v) => $v !== null)];
                }
            }
        }

        return $out;
    }

    private function metaMessage(array $m, array $names): array
    {
        $from = preg_replace('/\D/', '', (string) ($m['from'] ?? '')) ?: '0';
        $type = (string) ($m['type'] ?? 'text');
        $chat = $from.'@c.us';
        $base = ['id' => (string) ($m['id'] ?? 'x'), 'from' => $chat, 'to' => self::TEST_CHANNEL_PHONE.'@c.us', 'chatId' => $chat,
            'timestamp' => (int) ($m['timestamp'] ?? now()->getTimestamp()), 'fromMe' => false, 'isGroup' => false, 'senderPhone' => $from,
            'contact' => ['pushName' => $names[$from] ?? null]];
        if (isset($m['context']['id'])) {
            $base['quotedMessage'] = ['id' => $m['context']['id'], 'body' => ''];
        }
        if ($type === 'reaction') {
            return ['message.reaction', ['messageId' => $m['reaction']['message_id'] ?? '', 'chatId' => $chat, 'reaction' => $m['reaction']['emoji'] ?? '',
                'senderId' => $chat, 'timestamp' => $base['timestamp']]];
        }
        $media = fn (string $node) => ['mimetype' => $m[$node]['mime_type'] ?? 'application/octet-stream', 'filename' => $m[$node]['filename'] ?? null, 'omitted' => true];
        $data = match ($type) {
            'text' => ['type' => 'text', 'body' => (string) ($m['text']['body'] ?? '')],
            'image', 'video', 'document', 'sticker' => ['type' => $type, 'body' => (string) ($m[$type]['caption'] ?? ''), 'media' => $media($type), 'hasMedia' => true],
            'audio' => ['type' => !empty($m['audio']['voice']) ? 'voice' : 'audio', 'body' => '', 'media' => $media('audio'), 'hasMedia' => true],
            'location' => ['type' => 'location', 'body' => '', 'location' => ['latitude' => $m['location']['latitude'] ?? null, 'longitude' => $m['location']['longitude'] ?? null,
                'description' => $m['location']['name'] ?? null, 'address' => $m['location']['address'] ?? null]],
            'contacts' => ['type' => 'vcard', 'body' => "BEGIN:VCARD\nVERSION:3.0\nFN:".($m['contacts'][0]['name']['formatted_name'] ?? '—')."\nEND:VCARD"],
            'interactive', 'button' => ['type' => 'text', 'body' => (string) ($m['interactive']['button_reply']['title'] ?? $m['interactive']['list_reply']['title'] ?? $m['button']['text'] ?? ''),
                'button' => ['id' => (string) ($m['interactive']['button_reply']['id'] ?? $m['interactive']['list_reply']['id'] ?? $m['button']['payload'] ?? ''),
                    'text' => (string) ($m['interactive']['button_reply']['title'] ?? $m['interactive']['list_reply']['title'] ?? $m['button']['text'] ?? '')]],
            'order' => ['type' => 'order', 'body' => ''],
            default => ['type' => 'unknown', 'body' => ''],
        };

        return ['message.received', $base + $data];
    }
}
