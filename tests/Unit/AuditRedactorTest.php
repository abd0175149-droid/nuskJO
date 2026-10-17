<?php

namespace Tests\Unit;

use App\Support\AuditRedactor;
use Tests\TestCase;

/** لا أسرار ولا نصوص رسائل ولا جوازات ولا هواتف في changes، والموجّه بطوله وبصمته فقط. */
class AuditRedactorTest extends TestCase
{
    public function test_sensitive_keys_are_replaced(): void
    {
        $clean = AuditRedactor::clean([
            'wa_token' => 'EAAG'.str_repeat('x', 30), 'api_key' => 'k', 'password' => 'p', 'body' => 'نص العميل',
            'phone' => '+962790000000', 'passport_number' => 'N1234567', 'nested' => ['wa_phone' => '962790000000', 'model' => 'gemini'],
            'role' => ['from' => 'sales', 'to' => 'admin'],
        ]);

        foreach (['wa_token', 'api_key', 'password', 'body', 'phone', 'passport_number'] as $key) {
            $this->assertSame('[redacted]', $clean[$key], $key);
        }
        $this->assertSame(['wa_phone' => '[redacted]', 'model' => 'gemini'], $clean['nested']);
        // from/to في فروق السجل باقية (ليست حقول ميتا هنا)
        $this->assertSame(['from' => 'sales', 'to' => 'admin'], $clean['role']);
    }

    public function test_secret_values_under_harmless_keys_are_masked(): void
    {
        $clean = AuditRedactor::clean(['note' => 'key AIza'.str_repeat('a', 35).' leaked']);

        $this->assertStringNotContainsString('AIza', $clean['note']);
    }

    public function test_a_prompt_is_kept_as_length_and_short_hash(): void
    {
        $this->assertSame(['len' => 5, 'sha1' => substr(sha1('مرحبا'), 0, 12)], AuditRedactor::fingerprint('مرحبا'));
    }

    public function test_a_large_payload_is_truncated_and_marked(): void
    {
        $clean = AuditRedactor::clean(['a' => str_repeat('ب', 30000)]);

        $this->assertTrue($clean['truncated']);
        $this->assertLessThan(600, mb_strlen($clean['a']));
    }
}
