<?php

namespace Tests\Unit\Arch;

use Tests\TestCase;

/**
 * معاينة الرابط (E33): تُقرَّر في WhatsAppChannel::dispatch وحده (للموظف بالعلم والسياسة)، فلا يفعّلها البوت ولا أي مسار
 * آخر. لا 'preview_url' => true في app/، ولا كتابة على preview_url في القناة خارج dispatch.
 */
class PreviewUrlOnlyInDispatchTest extends TestCase
{
    public function test_no_code_turns_link_preview_on_outside_dispatch(): void
    {
        $offenders = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $code = (string) file_get_contents($file->getPathname());
            if (preg_match('/[\'"]preview_url[\'"]\s*(=>|\]\s*=)\s*true/i', $code)) {
                $offenders[] = substr($file->getPathname(), strlen(base_path()) + 1);
            }
        }

        $this->assertSame([], $offenders);
    }

    public function test_the_channel_writes_preview_url_inside_dispatch_only(): void
    {
        $code = (string) file_get_contents(app_path('Services/WhatsApp/WhatsAppChannel.php'));
        $start = strpos($code, 'private static function dispatch(');
        $end = strpos($code, 'private static function gate(', (int) $start);
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);

        preg_match_all("/\\['preview_url'\\]\\s*=/", $code, $writes, PREG_OFFSET_CAPTURE);
        $this->assertNotEmpty($writes[0], 'dispatch يقرر القيمة');
        foreach ($writes[0] as [, $offset]) {
            $this->assertTrue($offset > $start && $offset < $end, 'كتابة preview_url خارج dispatch');
        }
    }
}
