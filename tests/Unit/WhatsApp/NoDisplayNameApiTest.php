<?php

namespace Tests\Unit\WhatsApp;

use Tests\TestCase;

/**
 * حارس معماري (E31.9): اسم العرض واسم المستخدم للنشاط يُداران من WhatsApp Manager وحده. لا كود يطلب اسم عرض جديداً
 * (new_display_name)، ولا يعيد تسجيل الرقم (register)، ولا يحجز اسم مستخدم (username) عبر Graph: إعادة التسجيل خلال
 * 14 يوماً والتحقق الثنائي ومراجعة ميتا أثرها كبير ونادرة الحاجة.
 */
class NoDisplayNameApiTest extends TestCase
{
    /** @return array<string, string> المسار النسبي => الكود بلا تعليقات */
    private function appCode(): array
    {
        $out = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() === 'php') {
                $out[substr($file->getPathname(), strlen(base_path()) + 1)] = $this->stripComments((string) file_get_contents($file->getPathname()));
            }
        }

        return $out;
    }

    private function stripComments(string $php): string
    {
        $code = '';
        foreach (token_get_all($php) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }

    /** @return list<string> ما يخالف القاعدة في هذا الكود */
    private function violations(string $code): array
    {
        $found = [];
        if (str_contains($code, 'new_display_name')) {
            $found[] = 'new_display_name';
        }
        // مقاطع مسار Graph: GraphClient::request('POST', [$pnid, 'register'], …) أو url(…, 'username')
        if (preg_match_all('/GraphClient::(?:request|url)\s*\(([^;]*)/', $code, $calls)) {
            foreach ($calls[1] as $args) {
                if (preg_match('/[\'"](register|username)[\'"]/', $args, $m)) {
                    $found[] = 'segment:'.$m[1];
                }
            }
        }
        if (preg_match('#[\'"][^\'"]*/(register|username)\b#', $code, $m)) {
            $found[] = 'path:/'.$m[1];
        }

        return $found;
    }

    public function test_no_code_changes_the_display_name_or_registers_the_number_or_claims_a_username(): void
    {
        $offenders = [];
        foreach ($this->appCode() as $file => $code) {
            if ($v = $this->violations($code)) {
                $offenders[$file] = $v;
            }
        }

        $this->assertSame([], $offenders, 'اسم العرض واسم المستخدم وإعادة التسجيل من WhatsApp Manager وحده');
    }

    public function test_the_guard_catches_each_form(): void
    {
        $this->assertSame(['new_display_name'], $this->violations("<?php \$x = ['new_display_name' => 'NUSUK'];"));
        $this->assertSame(['segment:register'], $this->violations("<?php Graph\\GraphClient::request('POST', [\$pnid, 'register'], ['pin' => '000000']);"));
        $this->assertSame(['segment:username'], $this->violations("<?php GraphClient::url(\$pnid, 'username');"));
        $this->assertSame(['path:/register'], $this->violations("<?php \$u = \$base.'/register';"));
        $this->assertSame([], $this->violations($this->stripComments("<?php // new_display_name في تعليق لا يُحسب\n\$a = ['username' => \$c->wa_username];")));
    }
}
