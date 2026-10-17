<?php

namespace Tests\Feature\Ops;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ops:smoke-card (E26.8): يصيّر القالب الحقيقي لعرض ثابت غير محفوظ بالطريق نفسه، ويتحقق من الحجم والأبعاد ثم يحذف.
 * كروميوم مزيّف هنا: سكربت ينسخ صورة جاهزة إلى مسار --screenshot، فالاختبار لا يحتاج متصفحاً.
 */
class OpsSmokeCardTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/smoke-card-test-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        @rmdir($this->dir);
        parent::tearDown();
    }

    /** كروميوم مزيّف يكتب الصورة المعطاة (أو لا شيء) حيث يطلب --screenshot، ويحفظ HTML الذي فتحه */
    private function fakeChromium(?string $png): void
    {
        $copy = $png ? 'cp '.escapeshellarg($png).' "${a#--screenshot=}"' : 'true';
        $script = $this->dir.'/chromium';
        file_put_contents($script, "#!/bin/sh\nfor a in \"\$@\"; do\n  case \"\$a\" in\n    --screenshot=*) {$copy} ;;\n    file://*) cp \"\${a#file://}\" ".escapeshellarg($this->dir.'/seen.html')." ;;\n  esac\ndone\n");
        chmod($script, 0755);
        config(['services.chromium.path' => $script]);
    }

    private function png(int $width, int $height, bool $noise): string
    {
        $image = imagecreatetruecolor($width, $height);
        if ($noise) {
            for ($y = 0; $y < $height; $y += 2) {
                for ($x = 0; $x < $width; $x += 2) {
                    imagesetpixel($image, $x, $y, random_int(0, 0xFFFFFF));
                }
            }
        }
        $path = $this->dir.'/source-'.$width.'.png';
        imagepng($image, $path);
        imagedestroy($image);

        return $path;
    }

    public function test_a_full_size_card_passes_and_nothing_is_written(): void
    {
        $this->fakeChromium($this->png(1240, 1754, true));

        $this->assertSame(0, Artisan::call('ops:smoke-card'));
        $out = Artisan::output();

        $this->assertStringContainsString('1240×1754', $out);
        $this->assertStringContainsString('توليد البطاقة يعمل', $out);
        $this->assertSame(0, DB::table('offers')->count(), 'العرض الثابت لا يُحفظ');
        $this->assertSame([], glob(sys_get_temp_dir().'/card-smoke-*.html') ?: [], 'HTML المؤقت يُحذف');

        // القالب الحقيقي بسياسته الصارمة
        $html = (string) file_get_contents($this->dir.'/seen.html');
        $this->assertStringContainsString('بطاقة اختبار الدخان', $html);
        $this->assertMatchesRegularExpression("/script-src 'nonce-[0-9a-f]{32}'/", $html);
    }

    public function test_a_blank_or_missing_card_fails(): void
    {
        $this->fakeChromium($this->png(1240, 1754, false));
        $this->assertSame(1, Artisan::call('ops:smoke-card'), 'صورة فارغة أصغر من 20KB');

        $this->fakeChromium($this->png(300, 300, true));
        $this->assertSame(1, Artisan::call('ops:smoke-card'), 'أبعاد خاطئة');

        $this->fakeChromium(null);
        $this->assertSame(1, Artisan::call('ops:smoke-card'));
        $this->assertStringContainsString('تعذّر تصيير البطاقة', Artisan::output());
    }
}
