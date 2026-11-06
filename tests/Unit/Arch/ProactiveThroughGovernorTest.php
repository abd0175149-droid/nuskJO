<?php

namespace Tests\Unit\Arch;

use Tests\TestCase;

/**
 * حارس معماري (2026-11-06): كل إرسال استباقي يمر بطابور wa_outbox وحدود SendGovernor. الكتابة في الطابور من
 * Sending\Outbox وحده (فالمفتاح send_key إلزامي دائماً)، والإرسال منه في DispatchOutboxItem وحده بعد decide().
 */
class ProactiveThroughGovernorTest extends TestCase
{
    private function appFiles(): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->getExtension() === 'php') {
                $out[str_replace(DIRECTORY_SEPARATOR, '/', substr($f->getPathname(), strlen(base_path()) + 1))] = (string) file_get_contents($f->getPathname());
            }
        }

        return $out;
    }

    public function test_only_the_outbox_writer_creates_outbox_rows(): void
    {
        $writers = array_keys(array_filter($this->appFiles(), fn ($code) => preg_match('/WaOutbox::(create|insert|firstOrCreate|updateOrCreate)\s*\(|new WaOutbox\b/', $code) === 1));
        $this->assertSame(['app/Services/WhatsApp/Sending/Outbox.php'], $writers);
    }

    public function test_dispatch_decides_before_sending(): void
    {
        $code = (string) file_get_contents(app_path('Jobs/DispatchOutboxItem.php'));
        $decide = strpos($code, 'SendGovernor::decide(');
        $send = strpos($code, 'WhatsAppChannel::send(');
        $this->assertNotFalse($decide);
        $this->assertNotFalse($send);
        $this->assertLessThan($send, $decide);
        $this->assertStringContainsString("'send_key' => SendKey::outbox(\$item->id)", $code);
    }

    public function test_outbox_put_always_has_a_key(): void
    {
        $code = (string) file_get_contents(app_path('Services/WhatsApp/Sending/Outbox.php'));
        $this->assertMatchesRegularExpression('/public static function put\(string \$sendKey, array \$row\)/', $code);
    }
}
