<?php

namespace Tests\Unit\WhatsApp;

use App\Services\WhatsApp\OfferRef;
use PHPUnit\Framework\TestCase;

/** مرجع العرض في الرسالة المعبأة (E38): ASCII وحده وبحرف N كبير، ولا يطابق أرقام الهواتف ولا الكلام العادي. */
class OfferRefTest extends TestCase
{
    public function test_it_reads_the_offer_and_the_code(): void
    {
        $this->assertSame(['offer_id' => 45, 'qr_link_id' => 7], OfferRef::parse('مرحباً، أريد تفاصيل عرض «عمرة رجب» [N-45/Q7]'));
        $this->assertSame(['offer_id' => null, 'qr_link_id' => 12], OfferRef::parse('مرحباً، أريد الاستفسار عن عروضكم [Q12]'));
        $this->assertSame(['offer_id' => 45, 'qr_link_id' => null], OfferRef::parse('N-45'));
        $this->assertSame(['offer_id' => 45, 'qr_link_id' => null], OfferRef::parse('أريد العرض [N-45] من فضلك'));
    }

    public function test_ordinary_text_is_not_a_reference(): void
    {
        foreach (['رقمي 0791234567', 'غرفة N 4', 'n-45', 'VN-45', 'Q12', 'السعر 45 دينار', ''] as $text) {
            $this->assertSame(['offer_id' => null, 'qr_link_id' => null], OfferRef::parse($text), $text);
        }
    }

    public function test_the_token_and_the_match_after_meta_returns_the_text(): void
    {
        $this->assertSame('[N-45/Q7]', OfferRef::token(45, 7));
        $this->assertSame('[Q12]', OfferRef::token(null, 12));
        $this->assertTrue(OfferRef::matches('Hi «x» [N-45/Q7]', 45, 7));
        $this->assertFalse(OfferRef::matches('مرحباً، أريد تفاصيل عرض «x»', 45, 7));
        $this->assertFalse(OfferRef::matches('[N-46/Q7]', 45, 7));
    }
}
