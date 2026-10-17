<?php

namespace Tests\Feature\WhatsApp;

use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use App\Services\WhatsApp\BusinessProfileSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * ملف واتساب للأعمال (E31.8/E31.9): المرغوب من بيانات الشركة وحدها ضمن الحدود الرسمية، والمقارنة بما عند ميتا
 * (كاش 10 دقائق)، والمزامنة ترسل الحقول المحددة وحدها مع messaging_product ثم تقرأ للتحقق وتُدقَّق. قيم المتصفح تُتجاهل.
 */
class BusinessProfileSyncTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    private User $admin;

    /** ما عند ميتا الآن، تعدّله نداءات POST المزيّفة */
    private array $meta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureChannel();
        config(['features.env.business_profile_sync' => true]);
        $this->admin = User::factory()->admin()->create();
        foreach ([
            'wa_profile_about' => 'وكالة عمرة وسفر في عمّان — نرد من 9 صباحاً حتى 9 مساءً',
            'wa_profile_description' => 'باقات عمرة وتأشيرات وتذاكر طيران وفنادق.',
            'company_address' => 'عمّان، الأردن',
            'company_email' => 'info@example.com',
            'company_website' => 'https://www.example.com/',
            'company_facebook' => 'https://facebook.com/example',
        ] as $key => $value) {
            Setting::set($key, $value);
        }
        $this->meta = json_decode(file_get_contents(base_path('tests/Fixtures/whatsapp/business_profile.json')), true)['data'][0];
        Http::fake(['graph.facebook.com/*' => function (Request $r) {
            $path = (string) parse_url($r->url(), PHP_URL_PATH);
            if (str_ends_with($path, '/whatsapp_business_profile')) {
                if ($r->method() === 'POST') {
                    $this->meta = array_merge($this->meta, array_diff_key($r->data(), ['messaging_product' => 1]));

                    return Http::response(['success' => true]);
                }

                return Http::response(['data' => [$this->meta]]);
            }

            return Http::response(['verified_name' => 'NUSUK Gold Travel', 'name_status' => 'APPROVED', 'display_phone_number' => '15550783881', 'id' => self::TEST_PHONE_NUMBER_ID]);
        }]);
    }

    private function profileWrites(): array
    {
        return Http::recorded(fn (Request $r) => $r->method() === 'POST')->map(fn ($pair) => $pair[0]->data())->values()->all();
    }

    public function test_desired_values_follow_the_official_limits(): void
    {
        $status = fn () => array_map(fn ($d) => $d['status'], BusinessProfileSync::diff([], BusinessProfileSync::desired()));

        $this->assertSame('different', $status()['about']);
        Setting::set('wa_profile_about', str_repeat('ن', 140));
        $this->assertSame('too_long', $status()['about']);
        Setting::set('wa_profile_about', '');
        $this->assertSame('missing_local', $status()['about']);
        Setting::set('wa_profile_about', 'عروض *عمرة* رجب');
        $this->assertSame('invalid', $status()['about']);

        Setting::set('company_website', 'www.example.com');
        $this->assertSame('invalid', $status()['websites']);
        Setting::set('company_website', 'https://a.example.com https://b.example.com');
        $this->assertSame('too_long', $status()['websites'], 'رابطان مع فيسبوك ثلاثة');
        Setting::set('company_email', 'ليس بريداً');
        $this->assertSame('invalid', $status()['email']);
        $this->assertSame(BusinessProfileSync::VERTICAL, BusinessProfileSync::desired()['vertical']);
    }

    public function test_the_comparison_is_read_from_meta_and_cached_ten_minutes(): void
    {
        $r = $this->actingAs($this->admin)->getJson('/api/whatsapp/business-profile')->assertOk();

        $this->assertSame(['different', 'different', 'same', 'same', 'different', 'different'],
            array_values(array_map(fn ($d) => $d['status'], $r->json('diff'))));
        $this->assertSame('APPROVED', $r->json('identity.name_status'));
        $this->assertTrue($r->json('can.sync'));
        $calls = count(Http::recorded());

        $this->actingAs($this->admin)->getJson('/api/whatsapp/business-profile')->assertOk();
        $this->assertSame($calls, count(Http::recorded()), 'من الكاش');
        $this->actingAs($this->admin)->getJson('/api/whatsapp/business-profile?refresh=1')->assertOk();
        $this->assertGreaterThan($calls, count(Http::recorded()));
    }

    public function test_sync_sends_only_the_selected_fields_from_the_server_and_reads_them_back(): void
    {
        $r = $this->actingAs($this->admin)->postJson('/api/whatsapp/business-profile/sync', [
            'fields' => ['about', 'vertical'],
            // قيمة من المتصفح: تُتجاهل
            'about' => 'نص مزروع من المتصفح',
        ])->assertOk();

        $this->assertTrue($r->json('verified'));
        $this->assertSame([['messaging_product' => 'whatsapp', 'about' => Setting::get('wa_profile_about'), 'vertical' => 'TRAVEL']], $this->profileWrites());

        $audit = AuditLog::where('action', 'wa.business_profile')->sole();
        $this->assertSame(['warn', ['about', 'vertical']], [$audit->severity, $audit->changes['fields']]);
        $this->assertSame(['about' => 'مكتب سفر وعمرة', 'vertical' => 'OTHER'], $audit->changes['before']);
        $this->assertSame('TRAVEL', $audit->changes['after']['vertical']);
    }

    public function test_fields_outside_the_limits_are_never_sent(): void
    {
        Setting::set('wa_profile_about', str_repeat('ن', 200));

        $this->actingAs($this->admin)->postJson('/api/whatsapp/business-profile/sync', ['fields' => ['about', 'description']])
            ->assertStatus(422)->assertJsonPath('code', 'FIELDS_REJECTED')->assertJsonPath('rejected.about', 'too_long');

        $this->assertSame([], $this->profileWrites());
    }

    public function test_sync_needs_the_permission_the_flag_and_a_running_channel(): void
    {
        $desk = User::factory()->withPermissions(['whatsapp.view', 'whatsapp.view_all'], 'desk')->create();
        $this->actingAs($desk)->getJson('/api/whatsapp/business-profile')->assertForbidden();
        $this->actingAs($desk)->postJson('/api/whatsapp/business-profile/sync', ['fields' => ['about']])->assertForbidden();

        config(['features.env.business_profile_sync' => false]);
        $this->actingAs($this->admin)->postJson('/api/whatsapp/business-profile/sync', ['fields' => ['about']])->assertNotFound();

        config(['features.env.business_profile_sync' => true]);
        $this->configureChannel(['wa_suspended' => true]);
        $this->actingAs($this->admin)->postJson('/api/whatsapp/business-profile/sync', ['fields' => ['about']])
            ->assertStatus(422)->assertJsonPath('code', 'SENDING_SUSPENDED');
        $this->assertSame([], $this->profileWrites());
    }

    public function test_sync_is_limited_to_five_in_ten_minutes(): void
    {
        foreach (range(1, 5) as $_) {
            $this->actingAs($this->admin)->postJson('/api/whatsapp/business-profile/sync', ['fields' => ['vertical']])->assertOk();
        }
        $this->actingAs($this->admin)->postJson('/api/whatsapp/business-profile/sync', ['fields' => ['vertical']])->assertStatus(429);
    }

    public function test_company_settings_page_accepts_the_new_fields_within_limits(): void
    {
        $this->actingAs($this->admin)->put('/settings', ['settings' => [['key' => 'wa_profile_about', 'value' => str_repeat('ن', 140)]]])
            ->assertSessionHasErrors('values.wa_profile_about');
        $this->actingAs($this->admin)->put('/settings', ['settings' => [['key' => 'company_website', 'value' => 'ftp://example.com']]])
            ->assertSessionHasErrors('values.company_website');
        $this->actingAs($this->admin)->put('/settings', ['settings' => [['key' => 'wa_profile_about', 'value' => 'نبذة قصيرة صحيحة']]])
            ->assertSessionHasNoErrors();
        $this->assertSame('نبذة قصيرة صحيحة', Setting::get('wa_profile_about'));
    }
}
