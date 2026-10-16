<?php

namespace Tests\Unit\Arch;

use Tests\TestCase;

/**
 * حارس دفتر الإرسال (E19): كل إرسال آلي (مصدره bot أو system) يمرّر مفتاحاً حتمياً من SendKey،
 * فلا تخرج رسالة آلية مرتين بإعادة وظيفة أو مُصالِح. رسائل الموظف تأخذ مفتاحها من Idempotency-Key.
 */
class AutomatedSendsHaveKeyTest extends TestCase
{
    /** @return list<array{file: string, call: string}> */
    private function sendCalls(): array
    {
        $out = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php' || str_ends_with($file->getPathname(), 'WhatsAppChannel.php')) {
                continue;
            }
            $code = (string) file_get_contents($file->getPathname());
            if (!preg_match_all('/WhatsAppChannel::send(Text|Image|Buttons|List|ContactRequest)\(/', $code, $m, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            foreach ($m[0] as [$match, $offset]) {
                $start = $offset + strlen($match);
                $depth = 1;
                for ($i = $start; $i < strlen($code) && $depth > 0; $i++) {
                    $depth += ['(' => 1, ')' => -1][$code[$i]] ?? 0;
                }
                $out[] = ['file' => substr($file->getPathname(), strlen(base_path()) + 1), 'call' => substr($code, $offset, $i - $offset)];
            }
        }

        return $out;
    }

    public function test_every_automated_send_passes_a_send_key(): void
    {
        $calls = $this->sendCalls();
        $this->assertGreaterThan(10, count($calls), 'الحارس لا يجد الاستدعاءات');

        $missing = array_values(array_filter($calls, fn ($c) => preg_match("/'(bot|system)'/", $c['call']) && !str_contains($c['call'], 'SendKey::')));

        $this->assertSame([], array_map(fn ($c) => $c['file'].': '.preg_replace('/\s+/', ' ', mb_substr($c['call'], 0, 120)), $missing));
    }

    public function test_staff_sends_from_controllers_carry_the_idempotency_key(): void
    {
        $staff = array_values(array_filter($this->sendCalls(), fn ($c) => str_contains($c['file'], 'Http/Controllers') && str_contains($c['call'], "'staff'")));
        $this->assertNotEmpty($staff);

        foreach ($staff as $c) {
            // مفتاح الموظف (staffSendKey) أو مفتاح محسوب قبل الاستدعاء (السعر: SendKey::quote في $key)
            $this->assertMatchesRegularExpression('/staffSendKey|SendKey::|\$key\)/', $c['call'], $c['file']);
        }
    }
}
