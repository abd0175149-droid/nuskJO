<?php

namespace Tests\Feature\WhatsApp;

use App\Models\Notification;
use App\Models\User;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\WhatsAppChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * فشل إرسال برمز اعتماد أو قيود من ميتا ينبّه المدير فوراً، مرة في الساعة لكل رمز، بلا رقم العميل
 * ولا نص الرسالة، ويُحفظ «آخر خطأ اعتماد» لبطاقة الصحة. أخطاء العميل (النافذة، رقم بلا واتساب) لا تنبّه.
 */
class SendAccountErrorAlarmTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureChannel();
        $this->admin = User::factory()->admin()->create();
    }

    private function failWith(int $code, int $status = 400): void
    {
        $this->fakeGraph(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'x', 'type' => 'OAuthException', 'code' => $code]], $status)]);
    }

    private function alerts(): array
    {
        return Notification::where('user_id', $this->admin->id)->get(['title', 'body'])->toArray();
    }

    public function test_an_expired_token_alerts_once_without_customer_data(): void
    {
        $this->failWith(190, 401);
        $conv = WaConversation::factory()->windowOpen()->create(['wa_phone' => '962790000000', 'phone' => '962790000000']);

        foreach (['الرسالة الأولى السرية', 'الثانية', 'الثالثة'] as $text) {
            WhatsAppChannel::sendText($conv, $text);
        }

        $this->assertSame(3, WaMessage::where('status', 'failed')->count());
        $alerts = $this->alerts();
        $this->assertCount(1, $alerts);
        $this->assertSame('⛔ رمز ميتا منتهٍ أو ملغى — الإرسال متوقف', $alerts[0]['title']);
        $this->assertStringStartsWith('[190]', $alerts[0]['body']);
        $this->assertStringNotContainsString('962790000000', json_encode($alerts, JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString('السرية', json_encode($alerts, JSON_UNESCAPED_UNICODE));
        $this->assertSame(190, Cache::get('wa:last_account_error')['code']);
    }

    public static function accountErrors(): array
    {
        return [
            'payment' => [131042, '💳 مشكلة دفع في حساب ميتا'],
            'restricted' => [131031, '⛔ الحساب مقيّد من ميتا'],
            'permission range' => [200, '⛔ صلاحيات ميتا ناقصة'],
            'permission 10' => [10, '⛔ صلاحيات ميتا ناقصة'],
            'access denied' => [131005, '⛔ صلاحيات ميتا ناقصة'],
            'auth exception' => [0, '⛔ رمز ميتا منتهٍ أو ملغى — الإرسال متوقف'],
        ];
    }

    #[DataProvider('accountErrors')]
    public function test_account_errors_alert_with_their_own_title(int $code, string $title): void
    {
        $this->failWith($code);

        WhatsAppChannel::sendText(WaConversation::factory()->windowOpen()->create(), 'أهلاً');

        $this->assertSame([$title], array_column($this->alerts(), 'title'));
        $this->assertSame($code, Cache::get('wa:last_account_error')['code']);
    }

    public static function customerErrors(): array
    {
        return ['window closed at meta' => [131047], 'undeliverable' => [131026]];
    }

    #[DataProvider('customerErrors')]
    public function test_customer_side_errors_do_not_alert(int $code): void
    {
        $this->failWith($code);

        WhatsAppChannel::sendText(WaConversation::factory()->windowOpen()->create(), 'أهلاً');

        $this->assertSame([], $this->alerts());
        $this->assertNull(Cache::get('wa:last_account_error'));
    }

    public function test_a_rejected_media_lookup_alerts_like_a_send(): void
    {
        $this->failWith(190, 401);

        $this->assertFalse(WhatsAppChannel::fetchMedia('1003383421387256')['ok']);

        $this->assertSame(['⛔ رمز ميتا منتهٍ أو ملغى — الإرسال متوقف'], array_column($this->alerts(), 'title'));
    }
}
