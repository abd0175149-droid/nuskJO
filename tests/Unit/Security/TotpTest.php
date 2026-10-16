<?php

namespace Tests\Unit\Security;

use App\Support\Totp;
use PHPUnit\Framework\TestCase;

/** TOTP بحسب RFC 6238 بلا حزمة (E27): متجهات الاختبار الرسمية، والنافذة ±1، ومنع إعادة الاستعمال. */
class TotpTest extends TestCase
{
    /** سر RFC 6238 (الملحق B) لـ SHA1: «12345678901234567890» */
    private const SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    public function test_rfc6238_vectors_truncated_to_six_digits(): void
    {
        $vectors = [59 => '287082', 1111111109 => '081804', 1111111111 => '050471', 1234567890 => '005924', 2000000000 => '279037', 20000000000 => '353130'];
        foreach ($vectors as $time => $code) {
            $this->assertSame($code, Totp::at(self::SECRET, Totp::timestep($time)), (string) $time);
        }
    }

    public function test_base32_round_trip_and_new_secrets(): void
    {
        $this->assertSame(self::SECRET, Totp::base32Encode('12345678901234567890'));
        $this->assertSame('12345678901234567890', Totp::base32Decode(strtolower(self::SECRET)));
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', Totp::newSecret());
        $this->assertNotSame(Totp::newSecret(), Totp::newSecret());
        $this->assertSame('GEZD GNBV GY3T QOJQ GEZD GNBV GY3T QOJQ', Totp::grouped(self::SECRET));

        $this->expectException(\InvalidArgumentException::class);
        Totp::base32Decode('NOT-BASE32!');
    }

    public function test_window_is_one_step_each_side_and_a_used_step_is_refused(): void
    {
        $now = 1_700_000_000;
        $step = Totp::timestep($now);

        $this->assertSame($step, Totp::match(self::SECRET, Totp::at(self::SECRET, $step), null, 1, $now));
        $this->assertSame($step - 1, Totp::match(self::SECRET, Totp::at(self::SECRET, $step - 1), null, 1, $now));
        $this->assertSame($step + 1, Totp::match(self::SECRET, Totp::at(self::SECRET, $step + 1), null, 1, $now));
        $this->assertNull(Totp::match(self::SECRET, Totp::at(self::SECRET, $step - 2), null, 1, $now));

        // آخر خطوة قُبلت: الرمز نفسه لا يمر ثانية، وما قبلها كذلك
        $this->assertNull(Totp::match(self::SECRET, Totp::at(self::SECRET, $step), $step, 1, $now));
        $this->assertNull(Totp::match(self::SECRET, Totp::at(self::SECRET, $step - 1), $step, 1, $now));
        $this->assertSame($step + 1, Totp::match(self::SECRET, Totp::at(self::SECRET, $step + 1), $step, 1, $now));
    }

    public function test_codes_are_normalised_from_phone_keyboards(): void
    {
        $this->assertSame('123456', Totp::normalize(' ١٢٣ ٤٥٦ '));
        $this->assertSame('123456', Totp::normalize('۱۲۳-۴۵۶'));
        $this->assertSame('123456', Totp::normalize("123\u{200F}456"));
        $this->assertNull(Totp::normalize('12345'));
        $this->assertNull(Totp::normalize('12a456'));
    }

    public function test_otpauth_uri_carries_issuer_account_and_parameters(): void
    {
        $uri = Totp::uri(self::SECRET, 'nuskJO', 'admin@test.local');

        $this->assertStringStartsWith('otpauth://totp/nuskJO:admin%40test.local?secret='.self::SECRET.'&issuer=nuskJO', $uri);
        $this->assertStringContainsString('algorithm=SHA1&digits=6&period=30', $uri);
    }
}
