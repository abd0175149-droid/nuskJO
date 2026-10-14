<?php

namespace Tests\Feature\WhatsApp;

use App\Models\Notification;
use App\Models\User;
use App\Models\WaBotSetting;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\WhatsAppChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * عميل Graph داخل WhatsAppChannel: الإصدار والمعرّف من الإعداد ولا يغيّران المسار، ولا تحويلات،
 * وappsecret_proof خلف المفتاح، وأخطاء الاتصال لا تعيد الرابط، وتنبيه تقييد الحساب مرة في اليوم.
 */
class GraphClientTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    private const SEND_URL = 'https://graph.facebook.com/v25.0/106540352242922/messages';

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureChannel();
    }

    public static function badConfig(): array
    {
        return [
            'empty version' => [['services.whatsapp.graph_version' => '']],
            'malformed version' => [['services.whatsapp.graph_version' => 'v25.0/../v1']],
            'phone id with a slash' => [['services.whatsapp.phone_number_id' => '106540352242922/../me']],
        ];
    }

    #[DataProvider('badConfig')]
    public function test_invalid_version_or_phone_id_fails_before_any_request(array $config): void
    {
        $this->fakeGraph();
        config($config);

        $result = WhatsAppChannel::sendText(WaConversation::factory()->windowOpen()->create(), 'أهلاً');

        $this->assertSame(WhatsAppChannel::ERR_CONFIG, $result['code']);
        Http::assertNothingSent();
        $this->assertSame(0, WaMessage::count());
    }

    public function test_appsecret_proof_is_added_only_when_enabled(): void
    {
        $this->fakeGraph();
        $conv = WaConversation::factory()->windowOpen()->create();

        WhatsAppChannel::sendText($conv, 'بلا إثبات');
        config(['services.whatsapp.appsecret_proof' => true]);
        WhatsAppChannel::sendText($conv, 'مع إثبات');

        $proof = hash_hmac('sha256', 'test-token', self::TEST_APP_SECRET);
        Http::assertSent(fn (Request $r) => $r->url() === self::SEND_URL);
        Http::assertSent(fn (Request $r) => $r->url() === self::SEND_URL.'?appsecret_proof='.$proof && $r['text']['body'] === 'مع إثبات');
    }

    public function test_a_redirect_from_graph_is_not_followed(): void
    {
        $this->fakeGraph([
            'graph.facebook.com/*' => Http::response('', 302, ['Location' => 'https://collector.example/x']),
            'collector.example/*' => Http::response(['messages' => [['id' => 'wamid.X']]]),
        ]);

        $result = WhatsAppChannel::sendText(WaConversation::factory()->windowOpen()->create(), 'أهلاً');

        $this->assertFalse($result['ok']);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'collector.example'));
    }

    public function test_connection_failure_message_carries_no_url(): void
    {
        $this->fakeGraph(['graph.facebook.com/*' => Http::failedConnection('cURL error 28 for '.self::SEND_URL)]);
        $staff = User::factory()->withPermissions(['whatsapp.view', 'whatsapp.reply', 'whatsapp.view_all'])->create();

        $result = WhatsAppChannel::sendText(WaConversation::factory()->windowOpen()->create(), 'أهلاً', 'staff', $staff->id);

        $this->assertSame('HTTP_ERROR', $result['code']);
        $this->assertMatchesRegularExpression('/^تعذّر الاتصال بميتا \(مهلة أو شبكة\)\. رقم المرجع: [0-9a-f]{8}$/u', $result['error']);
    }

    public function test_131031_alerts_admins_once_an_hour_without_suspending(): void
    {
        $admin = User::factory()->admin()->create();
        $this->fakeGraph(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Account restricted', 'code' => 131031, 'fbtrace_id' => 'T']], 400)]);
        $conv = WaConversation::factory()->windowOpen()->create();

        $first = WhatsAppChannel::sendText($conv, 'أ');
        WhatsAppChannel::sendText($conv, 'ب');

        $this->assertSame(WhatsAppChannel::ERR_ACCOUNT, $first['code']);
        $this->assertSame(1, Notification::where('user_id', $admin->id)->where('title', '⛔ الحساب مقيّد من ميتا')->count());
        $this->assertFalse(WaBotSetting::current()->wa_suspended);
    }

    public function test_rejected_appsecret_proof_has_its_own_code(): void
    {
        config(['services.whatsapp.appsecret_proof' => true]);
        $this->fakeGraph(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid appsecret_proof provided in the API argument', 'code' => 100]], 400)]);

        $result = WhatsAppChannel::sendText(WaConversation::factory()->windowOpen()->create(), 'أهلاً');

        $this->assertSame(WhatsAppChannel::ERR_PROOF, $result['code']);
    }
}
