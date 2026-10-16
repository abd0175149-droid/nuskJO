<?php

namespace Tests\Feature\WhatsApp;

use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * صورة ملف الأعمال (E31.9): الرفع القابل للاستئناف بالترتيب (جلسة على التطبيق، ثم الملف بترويسة OAuth وfile_offset،
 * ثم profile_picture_handle)، وJPEG أو PNG مربعة 192px على الأقل وحدهما، وبلا App ID: 409.
 */
class ProfilePhotoUploadTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    private const SESSION = 'upload:MTphdHRhY2htZW50OjZhN2NmMjc3P2ZpbGVfbGVuZ3RoPTEwMA==?sig=ARZ_8NfSo2mt';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureChannel(['wa_app_id' => '1234567890']);
        config(['features.env.business_profile_photo' => true]);
        $this->admin = User::factory()->admin()->create();
        Http::fake(['graph.facebook.com/*' => function (Request $r) {
            $path = (string) parse_url($r->url(), PHP_URL_PATH);

            return match (true) {
                str_ends_with($path, '/1234567890/uploads') => Http::response(['id' => self::SESSION]),
                str_contains($path, '/upload:') => Http::response(['h' => '2:c2FtcGxlZHVwbG9hZA==']),
                default => Http::response(['success' => true]),
            };
        }]);
    }

    private function png(int $w = 256, int $h = 256): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 200, 160, 80));
        ob_start();
        imagepng($img);

        return (string) ob_get_clean();
    }

    private function upload(string $bytes, string $name = 'logo.png')
    {
        return $this->actingAs($this->admin)->post('/api/whatsapp/business-profile/photo',
            ['photo' => UploadedFile::fake()->createWithContent($name, $bytes)], ['Accept' => 'application/json']);
    }

    public function test_the_resumable_upload_runs_in_order_with_the_official_headers(): void
    {
        $bytes = $this->png();

        $this->upload($bytes)->assertOk();

        $calls = Http::recorded()->map(fn ($pair) => $pair[0])->values();
        $this->assertCount(3, $calls);

        [$session, $file, $profile] = $calls->all();
        $this->assertStringEndsWith('/1234567890/uploads', (string) parse_url($session->url(), PHP_URL_PATH));
        parse_str((string) parse_url($session->url(), PHP_URL_QUERY), $q);
        $this->assertSame(['profile.png', (string) strlen($bytes), 'image/png'], [$q['file_name'], $q['file_length'], $q['file_type']]);

        $this->assertStringEndsWith('/upload:MTphdHRhY2htZW50OjZhN2NmMjc3P2ZpbGVfbGVuZ3RoPTEwMA==', (string) parse_url($file->url(), PHP_URL_PATH));
        $this->assertStringContainsString('sig=ARZ_8NfSo2mt', $file->url());
        $this->assertSame('OAuth test-token', $file->header('Authorization')[0]);
        $this->assertSame('0', $file->header('file_offset')[0]);
        $this->assertSame($bytes, $file->body());

        $this->assertSame(['messaging_product' => 'whatsapp', 'profile_picture_handle' => '2:c2FtcGxlZHVwbG9hZA=='], $profile->data());
        $audit = AuditLog::where('action', 'wa.business_photo')->sole();
        $this->assertSame([hash('sha256', $bytes), 'upload'], [$audit->changes['sha256'], $audit->changes['source']]);
    }

    public function test_the_company_logo_can_be_used(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('brand/logo.png', $this->png(300, 290));
        Setting::set('company_logo', 'brand/logo.png');

        $this->actingAs($this->admin)->postJson('/api/whatsapp/business-profile/photo', ['use_company_logo' => true])->assertOk();

        $this->assertSame('company_logo', AuditLog::where('action', 'wa.business_photo')->sole()->changes['source']);
    }

    public function test_only_square_enough_jpeg_or_png_images_are_accepted(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="300" height="300"><rect width="300" height="300"/></svg>';
        $webp = 'RIFF'.pack('V', 26).'WEBPVP8 '.str_repeat("\0", 18);

        $this->upload($svg, 'logo.svg')->assertStatus(422)->assertJsonPath('code', 'PHOTO_TYPE');
        $this->upload($webp, 'logo.webp')->assertStatus(422)->assertJsonPath('code', 'PHOTO_TYPE');
        $this->upload($this->png(600, 300))->assertStatus(422)->assertJsonPath('code', 'PHOTO_SHAPE');
        $this->upload($this->png(100, 100))->assertStatus(422)->assertJsonPath('code', 'PHOTO_SMALL');

        Http::assertNothingSent();
    }

    public function test_without_an_app_id_the_upload_is_refused(): void
    {
        $this->configureChannel(['wa_app_id' => null]);

        $this->upload($this->png())->assertStatus(409)->assertJsonPath('code', 'APP_ID_MISSING');
        Http::assertNothingSent();
    }

    public function test_the_photo_needs_its_flag(): void
    {
        config(['features.env.business_profile_photo' => false]);

        $this->upload($this->png())->assertNotFound();
    }
}
