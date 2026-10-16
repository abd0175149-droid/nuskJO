<?php

namespace Tests\Feature\WhatsApp;

use App\Jobs\ProcessWhatsAppReply;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Setting;
use App\Models\User;
use App\Models\WaConversation;
use App\Models\WaCustomerNote;
use App\Models\WaMessage;
use App\Services\WhatsApp\IdentityMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\Concerns\SignsMetaWebhooks;
use Tests\TestCase;

/**
 * الأنواع الواردة الموثقة (E11.4–E11.5): نص مقروء لكل نوع بدل «[type]»، والاقتباس والوسائط والإحالة في أعمدتها،
 * ورسائل النظام (تغيّر الرقم أو المعرّف) تحديث هوية لا وارد يرد عليه البوت، ورقم شاركه العميل بالزر يملأ محادثته.
 * الحمولات مبنية من text.json (أرقام ميتا النموذجية فقط).
 */
class InboundTypesTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;
    use SignsMetaWebhooks;

    private const PHONE = '16505551234';

    private const BSUID = 'US.13491208655302741918';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->fakeGraph();
        $this->configureChannel();
        User::factory()->admin()->create();
    }

    /** يستبدل رسالة text.json بما يُمرَّر، مع وقت الآن */
    private function send(array $message, array $value = [])
    {
        $payload = json_decode(file_get_contents($this->fixturePath('text.json')), true);
        $base = ['from' => self::PHONE, 'id' => 'wamid.T'.uniqid(), 'timestamp' => (string) now()->timestamp];
        data_set($payload, 'entry.0.changes.0.value.messages.0', $message + $base);
        foreach ($value as $key => $v) {
            data_set($payload, "entry.0.changes.0.value.{$key}", $v);
        }

        return $this->postRawWebhook(json_encode($payload));
    }

    private function last(): WaMessage
    {
        return WaMessage::latest('id')->first();
    }

    public function test_each_documented_type_gets_a_readable_body(): void
    {
        $cases = [
            ['type' => 'location', 'location' => ['latitude' => 31.95, 'longitude' => 35.91, 'name' => 'المكتب', 'address' => 'عمّان']],
            ['type' => 'contacts', 'contacts' => [['name' => ['formatted_name' => 'سائق الحافلة'], 'phones' => [['phone' => '+1 650-555-3333', 'wa_id' => '16505553333']]]]],
            ['type' => 'order', 'order' => ['catalog_id' => '1', 'product_items' => [['product_retailer_id' => 'a'], ['product_retailer_id' => 'b']], 'text' => 'للعائلة']],
            ['type' => 'interactive', 'interactive' => ['type' => 'nfm_reply', 'nfm_reply' => ['name' => 'flow', 'response_json' => '{"pax":"3"}', 'body' => 'Sent']]],
            ['type' => 'reaction', 'reaction' => ['message_id' => 'wamid.OLD']],
            ['type' => 'sticker', 'sticker' => ['id' => 'm1', 'mime_type' => 'image/webp', 'sha256' => 'abc']],
            ['type' => 'something_new'],
        ];
        $expected = ['📍 المكتب، عمّان https://maps.google.com/?q=31.95,35.91', '👤 بطاقة جهة اتصال: سائق الحافلة',
            '🛒 طلب من الكتالوج: 2 أصناف — للعائلة', '📝 نموذج: flow — {"pax":"3"}', '↩️ أزال التفاعل', '🏷️ ملصق', '[something_new]'];

        foreach ($cases as $i => $message) {
            $this->send($message)->assertOk();
            $this->assertSame($expected[$i], $this->last()->body, $message['type']);
        }
        $this->assertSame('wamid.OLD', $this->last()->where('msg_type', 'reaction')->sole()->payload['_reaction_to']);
        $this->assertNull(WaConversation::sole()->wa_user_id, 'بطاقة شخص ثالث لا تمس الهوية');
        $this->assertSame(self::PHONE, WaConversation::sole()->phone);
    }

    public function test_quotes_media_referral_and_search_text_are_kept_in_their_columns(): void
    {
        $referral = ['source_url' => 'https://fb.me/x', 'source_type' => 'ad', 'source_id' => '123', 'headline' => 'عمرة رمضان'];
        $this->send([
            'type' => 'image', 'context' => ['from' => '15550783881', 'id' => 'wamid.QUOTED'], 'referral' => $referral,
            'image' => ['id' => 'media.1', 'mime_type' => 'image/jpeg', 'sha256' => 'deadbeef', 'caption' => 'هل العُمرة متاحة؟'],
        ])->assertOk();

        $m = $this->last();
        $this->assertSame('📷 صورة: هل العُمرة متاحة؟', $m->body);
        $this->assertSame(['wamid.QUOTED', 'media.1', 'image/jpeg', 'deadbeef', '106540352242922'], [$m->reply_to_wamid, $m->media_id, $m->media_mime, $m->media_sha256, $m->phone_number_id]);
        $this->assertSame('📷 صوره: هل العمره متاحه؟', $m->body_norm);
        $this->assertSame('عمرة رمضان', WaConversation::sole()->referral['headline']);

        // الإحالة الأولى تبقى
        $this->send(['type' => 'text', 'text' => ['body' => 'مرحبا'], 'referral' => ['headline' => 'إعلان آخر']])->assertOk();
        $this->assertSame('عمرة رمضان', WaConversation::sole()->referral['headline']);
    }

    public function test_a_number_change_updates_the_conversation_without_waking_the_bot(): void
    {
        config(['audit.extended' => true]);
        $client = Client::factory()->create(['phone' => '+16505551234']);
        $this->send(['type' => 'text', 'text' => ['body' => 'مرحبا']])->assertOk();
        Queue::fake();
        $conv = WaConversation::sole();
        $inboundAt = $conv->last_inbound_at;

        $this->send(['type' => 'system', 'system' => ['body' => 'User changed number', 'wa_id' => '16505553333', 'type' => 'user_changed_number']])->assertOk();

        $conv->refresh();
        $this->assertSame(['16505553333', $client->id], [$conv->phone, $conv->client_id], 'العميل يبقى: ميتا تشهد أنه الحساب نفسه');
        $this->assertTrue($conv->needs_attention);
        $this->assertSame('identity_change', $conv->attention_reason);
        $this->assertEquals($inboundAt, $conv->last_inbound_at, 'لا تفتح النافذة');
        $this->assertSame(['system', 'system', '🔄 غيّر العميل رقمه'], [$this->last()->source, $this->last()->msg_type, $this->last()->body]);
        $this->assertStringContainsString('حدّث رقمه في ملف العميل', WaCustomerNote::sole()->note);
        $this->assertSame(1, AuditLog::where('action', 'wa.identity_change')->count());
        Queue::assertNotPushed(ProcessWhatsAppReply::class);
    }

    public function test_a_number_change_to_a_number_already_in_use_changes_nothing(): void
    {
        WaConversation::factory()->create(['phone' => '16505553333', 'wa_phone' => '16505553333']);
        $this->send(['type' => 'text', 'text' => ['body' => 'مرحبا']])->assertOk();

        $this->send(['type' => 'system', 'system' => ['body' => 'changed', 'wa_id' => '16505553333', 'type' => 'user_changed_number']])->assertOk();

        $this->assertSame(self::PHONE, WaConversation::where('phone', self::PHONE)->sole()->phone);
        $this->assertStringContainsString('لمحادثة أخرى', WaCustomerNote::sole()->note);
    }

    public function test_a_shared_phone_from_the_request_button_fills_a_phoneless_conversation(): void
    {
        Setting::set('wa_identity_mode', IdentityMode::STAFF);
        $client = Client::factory()->create(['phone' => '+16505551234']);
        $conv = WaConversation::factory()->create(['phone' => null, 'wa_phone' => null, 'wa_user_id' => self::BSUID]);

        $this->send(['from' => null, 'from_user_id' => self::BSUID, 'type' => 'contacts',
            'contacts' => [['origin' => 'contact_request', 'name' => ['formatted_name' => 'أنا'], 'phones' => [['phone' => '+1 650 555 1234', 'wa_id' => self::PHONE]]]]],
            ['contacts' => [['profile' => ['name' => 'عميل'], 'user_id' => self::BSUID]]])->assertOk();

        $conv->refresh();
        $this->assertSame([self::PHONE, $client->id, 'auto'], [$conv->phone, $conv->client_id, $conv->client_link]);
    }

    public function test_a_status_with_both_number_and_id_fills_the_missing_id(): void
    {
        $conv = WaConversation::factory()->windowOpen()->create(['phone' => self::PHONE, 'wa_phone' => self::PHONE]);
        WaMessage::create(['conversation_id' => $conv->id, 'wamid' => 'wamid.OUT.1', 'direction' => 'out', 'source' => 'staff', 'msg_type' => 'text', 'body' => 'x', 'status' => 'sent']);
        $payload = json_decode(file_get_contents($this->fixturePath('status_delivered.json')), true);
        data_set($payload, 'entry.0.changes.0.value.statuses.0.id', 'wamid.OUT.1');
        data_set($payload, 'entry.0.changes.0.value.statuses.0.recipient_id', self::PHONE);
        data_set($payload, 'entry.0.changes.0.value.statuses.0.recipient_user_id', self::BSUID);

        $this->postRawWebhook(json_encode($payload))->assertOk();

        $this->assertSame(self::BSUID, $conv->fresh()->wa_user_id);
    }
}
