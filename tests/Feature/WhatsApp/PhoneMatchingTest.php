<?php

namespace Tests\Feature\WhatsApp;

use App\Models\Client;
use App\Models\User;
use App\Services\WhatsApp\PhoneNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الربط الآلي بالرقم الكامل (E11.3): لا مطابقة بآخر الأرقام عبر البلدان، ولا ربط بمحلي ملتبس، ولا بمعرّف ميتا،
 * وعميلان بالرقم نفسه = لا ربط آلي.
 */
class PhoneMatchingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_number_from_another_country_with_the_same_tail_is_not_matched(): void
    {
        Client::factory()->create(['phone' => '+966 50 123 4567']);

        $this->assertNull(PhoneNormalizer::findClient('971501234567'), 'آخر 9 أرقام متطابقة، والبلد مختلف');
        $this->assertNotNull(PhoneNormalizer::findClient('966501234567'));
    }

    public function test_jordanian_local_numbers_link_but_ambiguous_05_local_numbers_do_not(): void
    {
        $jo = Client::factory()->create(['phone' => '079-123-4567']);
        Client::factory()->create(['phone' => '0501234567']);

        $this->assertSame($jo->id, PhoneNormalizer::findClient('962791234567')?->id);
        $this->assertNull(PhoneNormalizer::findClient('966501234567'), '05 محلي قد يكون سعودياً أو إماراتياً');
    }

    public function test_two_clients_with_one_number_link_to_neither(): void
    {
        Client::factory()->create(['phone' => '+962791234567']);
        Client::factory()->create(['phone' => '00962791234567']);

        $this->assertNull(PhoneNormalizer::findClient('962791234567'));
    }

    public function test_a_business_scoped_user_id_is_never_a_phone(): void
    {
        Client::factory()->create(['phone' => '+962791234567']);
        User::factory()->create(['phone' => '+962791234567']);

        foreach (['JO.962791234567', 'JO.ENT.962791234567', 'abc962791234567'] as $notAPhone) {
            $this->assertSame('', PhoneNormalizer::canonical($notAPhone));
            $this->assertNull(PhoneNormalizer::findClient($notAPhone));
            $this->assertNull(PhoneNormalizer::findUser($notAPhone));
        }
        $this->assertTrue(PhoneNormalizer::isPhoneLike('+962791234567'));
        $this->assertFalse(PhoneNormalizer::isPhoneLike('JO.123456'));
    }
}
