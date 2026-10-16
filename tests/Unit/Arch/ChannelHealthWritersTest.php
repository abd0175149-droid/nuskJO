<?php

namespace Tests\Unit\Arch;

use Tests\TestCase;

/**
 * حارس معماري (E22): ChannelHealthChanged يُطلق من ChannelHealth وحدها (كل تغيّر مرة واحدة)، وأعمدة wa_health_* لا يكتبها
 * غيرها (والمتحكم يمر بها). الإيقاف اليدوي wa_suspended محور مستقل لا تكتبه الآلة.
 */
class ChannelHealthWritersTest extends TestCase
{
    /** @return array<string, string> المسار النسبي => الكود بلا تعليقات */
    private function appCode(): array
    {
        $out = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('app'), \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $code = '';
            foreach (token_get_all(file_get_contents($file->getPathname())) as $token) {
                if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $code .= is_array($token) ? $token[1] : $token;
            }
            $out[substr($file->getPathname(), strlen(base_path()) + 1)] = $code;
        }

        return $out;
    }

    public function test_channel_health_changed_is_fired_only_by_channel_health(): void
    {
        $fired = array_keys(array_filter($this->appCode(), fn ($code) => preg_match('/new\s+\\\\?(App\\\\Events\\\\WhatsApp\\\\)?ChannelHealthChanged\s*\(/', $code) === 1));

        $this->assertSame(['app/Services/WhatsApp/ChannelHealth.php'], $fired);
    }

    public function test_only_channel_health_writes_the_health_state(): void
    {
        // سجل الحقول (SettingsSchema) يعرّف العمود ولا يكتبه
        $code = array_diff_key($this->appCode(), ['app/Services/WhatsApp/Settings/SettingsSchema.php' => true]);
        $writers = array_keys(array_filter($code, fn ($c) => preg_match("/'wa_health'\s*=>/", $c) === 1));

        $this->assertSame(['app/Services/WhatsApp/ChannelHealth.php'], $writers);
    }

    public function test_the_machine_never_writes_the_manual_suspension(): void
    {
        $code = $this->appCode();

        $this->assertDoesNotMatchRegularExpression("/'wa_suspended'\s*=>\s*(false|0\b)/", $code['app/Services/WhatsApp/ChannelHealth.php']);
        foreach (['AccountEventHandler.php', 'SendHealthMonitor.php'] as $file) {
            $this->assertStringNotContainsString("'wa_suspended'", $code['app/Services/WhatsApp/'.$file], $file);
        }
    }
}
