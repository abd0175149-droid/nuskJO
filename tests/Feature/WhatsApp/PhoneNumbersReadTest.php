<?php

namespace Tests\Feature\WhatsApp;

use App\Services\Ops\SystemStatus;
use App\Services\WhatsApp\MetaHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\Concerns\FakesMetaReads;
use Tests\TestCase;

/** أرقام الحساب وحالتها (E23، C054): قراءة فقط ساعة في الكاش، ورقمنا معلَّم، ولا مسار لإنشاء رقم أو حذفه. */
class PhoneNumbersReadTest extends TestCase
{
    use ConfiguresWhatsApp;
    use FakesMetaReads;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ops.meta_checks' => true, 'services.whatsapp.waba_id' => '102290129340398']);
        $this->configureChannel();
    }

    public function test_both_numbers_are_listed_and_ours_is_marked_without_ids(): void
    {
        $this->fakeMetaReads();

        $r = MetaHealth::phoneNumbers();

        $this->assertSame('ok', $r['state']);
        $this->assertCount(2, $r['numbers']);
        $this->assertSame(['15550783881', true], [$r['numbers'][0]['display'], $r['numbers'][0]['ours']]);
        $this->assertSame(['16505553333', false, 'PENDING', 'RED'], [$r['numbers'][1]['display'], $r['numbers'][1]['ours'], $r['numbers'][1]['status'], $r['numbers'][1]['quality_rating']]);
        $this->assertStringNotContainsString('106540352242933', json_encode($r));
        parse_str((string) parse_url($this->metaRequests[0]->url(), PHP_URL_QUERY), $q);
        $this->assertStringContainsString('code_verification_status', $q['fields']);
        $this->assertSame('GET', $this->metaRequests[0]->method());
    }

    public function test_the_list_is_cached_for_an_hour(): void
    {
        $this->fakeMetaReads();

        MetaHealth::phoneNumbers();
        MetaHealth::phoneNumbers();
        $this->assertSame(1, $this->metaCallCount('numbers'));

        $this->travel(61)->minutes();
        MetaHealth::phoneNumbers();
        $this->assertSame(2, $this->metaCallCount('numbers'));
    }

    public function test_another_number_in_trouble_only_warns_and_a_missing_waba_is_explained(): void
    {
        $this->fakeMetaReads();
        $this->assertSame('warn', SystemStatus::snapshot(fresh: true)['sections']['numbers']['level']);

        config(['services.whatsapp.waba_id' => null]);
        $this->assertSame('no_waba', MetaHealth::phoneNumbers(fresh: true)['state']);
    }

    public function test_no_route_creates_registers_or_deletes_a_meta_number(): void
    {
        $offenders = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => array_intersect(['POST', 'PUT', 'PATCH', 'DELETE'], $r->methods())
                // «register» لرقم ميتا (لا «تسجيل محظور غير متتبَّع» في E31)
                && preg_match('/phone[-_]?numbers|request[-_]code|verify[-_]code|(?<!untracked\/)register/i', $r->uri()))
            ->map(fn ($r) => $r->uri())->values()->all();

        $this->assertSame([], $offenders);
    }
}
