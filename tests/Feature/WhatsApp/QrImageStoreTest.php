<?php

namespace Tests\Feature\WhatsApp;

use App\Services\WhatsApp\QrImageStore;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * صورة الرمز من ميتا (E38.3): https ومضيف ميتا وحدهما، ولا تحويل، وسقف 1MB، والنوع بـ finfo. PNG عام للطباعة،
 * وSVG على القرص الخاص وحده (قد يحمل سكربتاً).
 */
class QrImageStoreTest extends TestCase
{
    private const URL = 'https://scontent.xx.fbcdn.net/v/qr-image?oh=sig';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
    }

    private function png(): string
    {
        $img = imagecreatetruecolor(32, 32);
        ob_start();
        imagepng($img);

        return (string) ob_get_clean();
    }

    private function assertNothingStored(): void
    {
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_a_png_from_a_meta_host_is_kept_on_the_public_disk(): void
    {
        Http::fake(['scontent.xx.fbcdn.net/*' => Http::response($this->png())]);

        $path = QrImageStore::fetch(self::URL, 'PNG');

        $this->assertMatchesRegularExpression('#^wa-qr/[0-9a-f-]{36}\.png$#', $path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_svg_goes_to_the_private_disk_only(): void
    {
        Http::fake(['scontent.xx.fbcdn.net/*' => Http::response('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 8 8"><path d="M0 0h1v1H0z"/></svg>')]);

        $path = QrImageStore::fetch(self::URL, 'SVG');

        Storage::disk('local')->assertExists($path);
        Storage::disk('public')->assertMissing($path);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_plain_http_and_foreign_hosts_are_refused_without_a_request(): void
    {
        Http::fake();

        $this->assertNull(QrImageStore::fetch('http://scontent.xx.fbcdn.net/qr.png', 'PNG'));
        $this->assertNull(QrImageStore::fetch('https://evil.example/qr.png', 'PNG'));
        $this->assertNull(QrImageStore::fetch('https://fbcdn.net.evil.example/qr.png', 'PNG'));

        Http::assertNothingSent();
        $this->assertNothingStored();
    }

    public function test_redirects_oversize_bodies_and_wrong_types_are_refused(): void
    {
        Http::fake(['scontent.xx.fbcdn.net/*' => Http::sequence()
            ->push('', 302, ['Location' => 'https://evil.example/qr.png'])
            ->push(str_repeat('A', 2 * 1024 * 1024), 200)
            ->push('<html><body>not an image</body></html>', 200, ['Content-Type' => 'image/png'])
            ->push($this->png(), 200)]);

        $this->assertNull(QrImageStore::fetch(self::URL, 'PNG'), 'تحويل');
        $this->assertNull(QrImageStore::fetch(self::URL, 'PNG'), '2MB');
        $this->assertNull(QrImageStore::fetch(self::URL, 'PNG'), 'text/html');
        $this->assertNull(QrImageStore::fetch(self::URL, 'SVG'), 'PNG طُلب SVG');

        Http::assertSentCount(4);
        $this->assertNothingStored();
    }
}
