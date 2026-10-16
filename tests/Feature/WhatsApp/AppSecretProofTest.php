<?php

namespace Tests\Feature\WhatsApp;

use App\Models\Notification;
use App\Models\User;
use App\Models\WaConversation;
use App\Services\WhatsApp\ChannelDoctor;
use App\Services\WhatsApp\Graph\GraphClient;
use App\Services\WhatsApp\WhatsAppChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * appsecret_proof في كل نداء Graph (E18): الإرسال والخطوة الأولى لتنزيل الوسائط وdebug_token (على رمز التطبيق المرسل نفسه)،
 * ولا إثبات على رابط lookaside. والعدّادات تطابق النداءات: شرط تفعيل Require App Secret في E26.
 */
class AppSecretProofTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    private const MEDIA_ID = '1003383421387256';

    private const BYTES = 'OggS-voice-bytes';

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureChannel(['wa_app_id' => '123456789012345']);
    }

    /** Http::fake يراكم الأنماط والأول يغلب: كل اختبار يزيّف ما يحتاجه */
    private function fakeMeta(): void
    {
        Http::fake([
            'graph.facebook.com/*/debug_token*' => Http::response(['data' => ['is_valid' => true, 'app_id' => '123456789012345', 'scopes' => []]]),
            'graph.facebook.com/*/'.self::MEDIA_ID.'*' => Http::response([
                'url' => 'https://lookaside.fbsbx.com/whatsapp_business/attachments/?mid='.self::MEDIA_ID,
                'mime_type' => 'audio/ogg', 'file_size' => strlen(self::BYTES), 'sha256' => hash('sha256', self::BYTES), 'id' => self::MEDIA_ID,
            ]),
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT.1']]]),
            'lookaside.fbsbx.com/*' => Http::response(self::BYTES, 200, ['Content-Type' => 'audio/ogg']),
        ]);
    }

    private function exercise(): void
    {
        $this->fakeMeta();
        WhatsAppChannel::sendText(WaConversation::factory()->windowOpen()->create(), 'أهلاً');
        $this->assertTrue(WhatsAppChannel::fetchMedia(self::MEDIA_ID)['ok']);
        WhatsAppChannel::debugToken();
    }

    /** @return array<string, ?string> رابط => قيمة الإثبات */
    private function proofs(string $host): array
    {
        $out = [];
        foreach (Http::recorded() as [$request]) {
            /** @var Request $request */
            if (parse_url($request->url(), PHP_URL_HOST) === $host) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                $out[] = ['url' => $request->url(), 'proof' => $query['appsecret_proof'] ?? null, 'auth' => $request->header('Authorization')[0] ?? null];
            }
        }

        return $out;
    }

    public function test_every_graph_call_carries_the_proof_of_the_token_it_sends(): void
    {
        config(['services.whatsapp.appsecret_proof' => true]);

        $this->exercise();

        $graph = $this->proofs('graph.facebook.com');
        $this->assertCount(3, $graph, 'الإرسال والبيانات الوصفية وdebug_token');
        foreach ($graph as $call) {
            $token = substr((string) $call['auth'], strlen('Bearer '));
            $this->assertSame(hash_hmac('sha256', $token, self::TEST_APP_SECRET), $call['proof'], $call['url']);
        }
        $this->assertSame('Bearer 123456789012345|'.self::TEST_APP_SECRET, end($graph)['auth'], 'debug_token برمز التطبيق');

        $lookaside = $this->proofs('lookaside.fbsbx.com');
        $this->assertCount(1, $lookaside);
        $this->assertNull($lookaside[0]['proof'], 'رابط الوسائط ليس نداء Graph');
        $this->assertSame('Bearer test-token', $lookaside[0]['auth']);

        $today = GraphClient::counters(1)[0];
        $this->assertSame([3, 3, 0], [$today['calls'], $today['proof'], $today['proof_err']]);
    }

    public function test_no_proof_when_switched_off(): void
    {
        $this->exercise();

        foreach ($this->proofs('graph.facebook.com') as $call) {
            $this->assertNull($call['proof'], $call['url']);
        }
        $today = GraphClient::counters(1)[0];
        $this->assertSame([3, 0], [$today['calls'], $today['proof']]);
        $this->assertFalse(GraphClient::proofSummary()['ready_for_require']);
    }

    public function test_a_rejected_proof_is_counted_classified_and_alerts(): void
    {
        config(['services.whatsapp.appsecret_proof' => true, 'features.env.meta_error_v2' => true]);
        User::factory()->admin()->create();
        $body = json_decode((string) file_get_contents(base_path('tests/Fixtures/whatsapp/error_100_appsecret_proof.json')), true);
        Http::fake(['graph.facebook.com/*' => Http::response($body, 400)]);

        $result = WhatsAppChannel::sendText(WaConversation::factory()->windowOpen()->create(), 'أهلاً');

        $this->assertSame(['APPSECRET_PROOF_INVALID', 'auth'], [$result['code'], $result['category']]);
        $this->assertSame(1, GraphClient::counters(1)[0]['proof_err']);
        $this->assertSame(1, Notification::count());
        $this->assertContains('APPSECRET_PROOF_REJECTED', array_column(ChannelDoctor::checks(), 'code'));
    }
}
