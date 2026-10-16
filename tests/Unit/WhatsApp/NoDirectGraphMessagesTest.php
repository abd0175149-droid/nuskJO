<?php

namespace Tests\Unit\WhatsApp;

use Tests\TestCase;

/**
 * لا إرسال إلى ميتا خارج dispatch (E32، رأس WhatsAppChannel): مقطع 'messages' في نداء Graph لا يظهر إلا داخل dispatch()
 * ودفتره ledger()، فكل صادر يمر بالأقفال والسياسة والدفتر. فحص نصي بلا تعليقات.
 * الاستثناء الوحيد markRead (E35): إشعار قراءة ومؤشر «يكتب…» لا رسالة، بقفلي القناة والإيقاف الطارئ وحدهما.
 */
class NoDirectGraphMessagesTest extends TestCase
{
    /** @return array<string, string> المسار => الكود بلا تعليقات */
    private function appCode(): array
    {
        $out = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $code = '';
            foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
                if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $code .= is_array($token) ? $token[1] : $token;
            }
            $out[substr($file->getPathname(), strlen(base_path()) + 1)] = $code;
        }

        return $out;
    }

    public function test_the_messages_segment_is_only_called_from_the_channel(): void
    {
        $offenders = array_keys(array_filter($this->appCode(), fn (string $code, string $path) => $path !== 'app/Services/WhatsApp/WhatsAppChannel.php'
            && preg_match("/['\"]messages['\"]\s*\]/", $code) === 1 && str_contains($code, 'GraphClient'), ARRAY_FILTER_USE_BOTH));

        $this->assertSame([], $offenders, 'إرسال إلى /messages خارج WhatsAppChannel');
    }

    public function test_inside_the_channel_only_dispatch_and_its_ledger_post_messages(): void
    {
        $code = $this->appCode()['app/Services/WhatsApp/WhatsAppChannel.php'];
        preg_match_all('/function\s+(\w+)\s*\(/', $code, $functions, PREG_OFFSET_CAPTURE);
        $callers = [];
        foreach ($functions[1] as $i => [$name, $offset]) {
            $end = $functions[1][$i + 1][1] ?? strlen($code);
            if (preg_match("/GraphClient::request\(\s*'POST'\s*,\s*\[[^\]]*'messages'\s*\]/", substr($code, $offset, $end - $offset))) {
                $callers[] = $name;
            }
        }

        $this->assertSame(['markRead', 'dispatch', 'ledger'], $callers);
    }
}
