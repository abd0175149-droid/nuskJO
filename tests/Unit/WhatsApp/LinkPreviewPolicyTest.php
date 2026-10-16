<?php

namespace Tests\Unit\WhatsApp;

use App\Services\WhatsApp\LinkPreviewPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * معاينة الرابط (E33): للروابط العامة وحدها. لا مضيف النظام ولا خوادمنا المشتركة ولا عنوان داخلي ولا رابط موقّع أو مؤقت
 * ولا مسار ملفات أو واجهة برمجية، والسبب عربي للواجهة.
 */
class LinkPreviewPolicyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://nusk.example', 'whatsapp.preview_block_hosts' => ['grade.sbs']]);
    }

    public function test_a_public_https_link_is_allowed(): void
    {
        $this->assertSame(['allowed' => true, 'reason' => null], LinkPreviewPolicy::check('تفاصيل الحجز هنا: https://www.nusuk.sa/ar/umrah'));
        $this->assertTrue(LinkPreviewPolicy::allows('https://www.nusuk.sa'));
    }

    public static function refused(): array
    {
        return [
            'http' => ['http://www.nusuk.sa'],
            'app host' => ['https://nusk.example/whatsapp/inbox'],
            'shared servers' => ['https://demo.grade.sbs/offers'],
            'shared root' => ['https://grade.sbs'],
            'signature' => ['https://www.nusuk.sa/file?signature=abc'],
            'token' => ['https://www.nusuk.sa/file?Token=abc'],
            'expires' => ['https://cdn.example.org/a.pdf?expires=99'],
            'amazon signed' => ['https://bucket.example.org/a.pdf?X-Amz-Signature=abc'],
            'storage path' => ['https://www.nusuk.sa/storage/a.pdf'],
            'api path' => ['https://www.nusuk.sa/api/offers'],
            'short link path' => ['https://www.nusuk.sa/s/abc'],
            'ip' => ['https://10.0.0.5/page'],
            'ipv6' => ['https://[::1]/page'],
            'localhost' => ['https://localhost/page'],
            'too long' => ['https://www.nusuk.sa/'.str_repeat('a', 2100)],
            'no link' => ['لا رابط هنا'],
        ];
    }

    #[DataProvider('refused')]
    public function test_internal_signed_or_odd_links_are_refused_with_an_arabic_reason(string $text): void
    {
        $result = LinkPreviewPolicy::check($text);

        $this->assertFalse($result['allowed']);
        $this->assertMatchesRegularExpression('/[\x{0600}-\x{06FF}]/u', (string) $result['reason']);
    }

    public function test_only_the_first_link_decides_and_has_link_detects_any_link(): void
    {
        $this->assertFalse(LinkPreviewPolicy::allows('https://nusk.example/x ثم https://www.nusuk.sa'));
        $this->assertTrue(LinkPreviewPolicy::hasLink('انظر http://example.org'));
        $this->assertFalse(LinkPreviewPolicy::hasLink('بلا رابط'));
    }
}
