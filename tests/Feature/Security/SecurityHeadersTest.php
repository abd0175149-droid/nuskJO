<?php

namespace Tests\Feature\Security;

use App\Models\Offer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Vite;
use Tests\TestCase;

/**
 * سياسة أمن المحتوى (E26.3): nonce جديد لكل طلب يحمله سكربت الثيم و@routes وكل وسم من @vite، وتقارير فقط افتراضياً،
 * والفرض بـ CSP_MODE=enforce أو بالعلم csp_enforce، ولا شيء مع off. معاينة البطاقة بسياستها الأشد.
 */
class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    private ?string $publicDir = null;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://nuskjo.example.test', 'csp.mode' => 'report', 'csp.connect_extra' => []]);
    }

    protected function tearDown(): void
    {
        if ($this->publicDir && is_dir($this->publicDir)) {
            @unlink($this->publicDir.'/build/manifest.json');
            @rmdir($this->publicDir.'/build');
            @rmdir($this->publicDir);
        }
        parent::tearDown();
    }

    /** Vite الحقيقي ببيان صغير في مجلد مؤقت، فتظهر وسوم @vite كما في الإنتاج */
    private function withBuiltAssets(): void
    {
        $this->publicDir = sys_get_temp_dir().'/csp-public-'.bin2hex(random_bytes(4));
        mkdir($this->publicDir.'/build', 0777, true);
        file_put_contents($this->publicDir.'/build/manifest.json', json_encode([
            'resources/js/app.js' => ['file' => 'assets/app-abc.js', 'src' => 'resources/js/app.js', 'isEntry' => true, 'imports' => ['_vendor.js']],
            '_vendor.js' => ['file' => 'assets/vendor-def.js'],
            'resources/css/app.css' => ['file' => 'assets/app-ghi.css', 'src' => 'resources/css/app.css', 'isEntry' => true],
        ]));
        $this->app->usePublicPath($this->publicDir);
        $this->withVite();
        Vite::clearResolvedInstance();
    }

    private function nonceOf(string $policy): string
    {
        $this->assertSame(1, preg_match("/'nonce-([A-Za-z0-9+\/=_-]+)'/", $policy, $m), $policy);

        return $m[1];
    }

    public function test_report_mode_carries_a_fresh_nonce_on_every_inline_and_bundled_script(): void
    {
        $this->withBuiltAssets();

        $first = $this->get('/login')->assertOk();
        $policy = (string) $first->headers->get('Content-Security-Policy-Report-Only');
        $this->assertFalse($first->headers->has('Content-Security-Policy'), 'تقارير فقط افتراضياً');
        $nonce = $this->nonceOf($policy);
        $html = $first->getContent();

        // كل وسم script في الصفحة يحمل nonce الطلب: سكربت الثيم و@routes ووسوم @vite (عدا بيانات Inertia JSON)
        preg_match_all('/<script\b(?![^>]*type="application\/json")[^>]*>/i', $html, $tags);
        $this->assertGreaterThanOrEqual(3, count($tags[0]), $html);
        foreach ($tags[0] as $tag) {
            $this->assertStringContainsString('nonce="'.$nonce.'"', $tag);
        }
        $this->assertStringContainsString('assets/app-abc.js', $html);
        $this->assertMatchesRegularExpression('#<link[^>]+assets/app-ghi\.css[^>]+nonce="'.preg_quote($nonce, '#').'"#', $html);
        $this->assertSame('csp="'.url('/csp-report').'"', $first->headers->get('Reporting-Endpoints'));
        $this->assertStringContainsString('report-uri /csp-report; report-to csp', $policy);

        $second = $this->get('/login');
        $this->assertNotSame($nonce, $this->nonceOf((string) $second->headers->get('Content-Security-Policy-Report-Only')));
    }

    public function test_enforce_mode_blocks_without_eval_or_external_script_hosts(): void
    {
        config(['csp.mode' => 'enforce']);

        $policy = (string) $this->get('/login')->assertOk()->headers->get('Content-Security-Policy');

        foreach (["object-src 'none'", "frame-ancestors 'self'", "base-uri 'self'", "form-action 'self'", 'wss://nuskjo.example.test', 'https://*.googleapis.com', "worker-src 'self' blob:"] as $directive) {
            $this->assertStringContainsString($directive, $policy);
        }
        foreach (["'unsafe-eval'", 'cdnjs', 'fonts.googleapis', 'fonts.gstatic'] as $absent) {
            $this->assertStringNotContainsString($absent, $policy);
        }
        $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-[^']+';/", $policy, 'لا مضيف خارجي ولا unsafe-inline في script-src');
    }

    public function test_the_flag_turns_report_into_enforce(): void
    {
        config(['features.env.csp_enforce' => true]);
        $response = $this->get('/login');
        $this->assertTrue($response->headers->has('Content-Security-Policy'));
        $this->assertFalse($response->headers->has('Content-Security-Policy-Report-Only'));
    }

    public function test_off_removes_the_policy_and_the_nonce(): void
    {
        // تطبيق جديد لكل طلب في الإنتاج: لا nonce من طلب سابق (طلب واحد هنا لهذا السبب)
        config(['csp.mode' => 'off', 'features.env.csp_enforce' => true]);
        $off = $this->get('/login')->assertOk();
        $this->assertFalse($off->headers->has('Content-Security-Policy'));
        $this->assertFalse($off->headers->has('Content-Security-Policy-Report-Only'));
        $this->assertFalse($off->headers->has('Reporting-Endpoints'));
        $this->assertStringNotContainsString('nonce=', $off->getContent());
    }

    public function test_extra_connect_sources_are_added_and_malformed_ones_dropped(): void
    {
        config(['csp.connect_extra' => ['wss://ws.example.test', 'https://x.test; script-src *', 'not a url']]);

        $policy = (string) $this->get('/login')->headers->get('Content-Security-Policy-Report-Only');

        $this->assertStringContainsString('wss://ws.example.test', $policy);
        $this->assertStringNotContainsString('script-src *', $policy);
        $this->assertStringNotContainsString('not a url', $policy);
    }

    public function test_the_card_preview_keeps_its_own_stricter_policy(): void
    {
        $admin = User::factory()->admin()->create();
        $offer = Offer::factory()->ready()->create();

        $response = $this->actingAs($admin)->get(route('offers.card.preview', $offer))->assertOk();

        $this->assertFalse($response->headers->has('Content-Security-Policy-Report-Only'), 'لا سياسة التطبيق فوقها');
        $policy = (string) $response->headers->get('Content-Security-Policy');
        $this->assertStringStartsWith("default-src 'none'; img-src data:;", $policy);
        $this->assertStringContainsString("frame-ancestors 'self'", $policy);
        $this->assertStringNotContainsString("'unsafe-inline'; font-src", $policy);
        $nonce = $this->nonceOf($policy);
        $this->assertStringContainsString('<script nonce="'.$nonce.'">', $response->getContent());
        $this->assertStringContainsString("script-src 'nonce-{$nonce}'", $response->getContent(), 'meta بالسياسة نفسها');
    }

    public function test_json_files_and_responses_with_their_own_policy_are_left_alone(): void
    {
        Route::middleware('web')->get('/__csp-own', fn () => response('<p>x</p>')->header('Content-Security-Policy', 'sandbox'));
        Route::middleware('web')->get('/__csp-json', fn () => response()->json(['ok' => true]));

        $own = $this->get('/__csp-own');
        $this->assertSame('sandbox', $own->headers->get('Content-Security-Policy'));
        $this->assertFalse($own->headers->has('Content-Security-Policy-Report-Only'));

        $this->assertFalse($this->get('/__csp-json')->headers->has('Content-Security-Policy-Report-Only'));
        $this->assertFalse($this->get('/api/whatsapp/webhook')->headers->has('Content-Security-Policy-Report-Only'), 'الويبهوك خارج web');
    }
}
