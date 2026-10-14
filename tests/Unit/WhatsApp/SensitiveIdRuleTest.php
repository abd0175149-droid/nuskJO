<?php

namespace Tests\Unit\WhatsApp;

use App\Models\WaConversation;
use App\Services\WhatsApp\Outbound\Rules\SensitiveIdRule;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * المعرّفات الحساسة في الرسائل الصادرة (E15.9): بطاقة تجتاز Luhn، وIBAN يجتاز mod-97، وجواز، ورقم وطني.
 * وأرقام الهواتف والأسعار وأرقام الطلبات لا تُعدّ منها.
 */
class SensitiveIdRuleTest extends TestCase
{
    private function reasons(string $text): array
    {
        return (new SensitiveIdRule)->check(new WaConversation, [$text], 'reply');
    }

    public static function sensitive(): array
    {
        return [
            'بطاقة بمسافات' => ['4111 1111 1111 1111', 'card'],
            'بطاقة بشرطات' => ['5500-0000-0000-0004', 'card'],
            'بطاقة بأرقام عربية' => ['٤١١١١١١١١١١١١١١١', 'card'],
            'IBAN متصل' => ['GB82WEST12345698765432', 'iban'],
            'IBAN بمسافات' => ['GB82 WEST 1234 5698 7654 32', 'iban'],
            'جواز' => ['N1234567', 'passport'],
            'رقم وطني' => ['9871234567', 'national_id'],
        ];
    }

    #[DataProvider('sensitive')]
    public function test_sensitive_identifiers_are_found(string $text, string $reason): void
    {
        $this->assertContains($reason, $this->reasons("القيمة: {$text}"));
    }

    public static function ordinary(): array
    {
        return [
            'هاتف محلي' => ['0791234567'],
            'هاتف دولي' => ['+962791234567'],
            'هاتف دولي بلا +' => ['962791234567'],
            'رقم لا يجتاز Luhn' => ['4111 1111 1111 1112'],
            'IBAN لا يجتاز mod-97' => ['GB82WEST12345698765431'],
            'سعر' => ['الإجمالي 1,250.000 د.أ'],
            'رقم طلب' => ['QR-20261014-0042'],
            'تاريخ' => ['2026-11-05'],
        ];
    }

    #[DataProvider('ordinary')]
    public function test_phones_prices_and_references_are_not_sensitive(string $text): void
    {
        $this->assertSame([], $this->reasons($text));
    }

    public function test_the_company_iban_in_the_allow_list_passes(): void
    {
        config(['whatsapp.outbound_policy.allow_ibans' => ['GB82WEST12345698765432']]);

        $this->assertSame([], $this->reasons('حسابنا GB82 WEST 1234 5698 7654 32'));
    }
}
