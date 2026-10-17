<?php

namespace Tests\Unit\WhatsApp;

use App\Models\Setting;
use App\Models\WaAutoRule;
use App\Services\WhatsApp\RuleTextGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CapturesLogs;
use Tests\TestCase;

/**
 * حارس نص الرد الحتمي (E37): نص ثابت يصل كل من يسأل، فلا سعر ولا خصم برقم ولا بيانات دفع ولا هاتف مكتوب ولا رابط
 * داخلي ولا طلب لصور الوثائق. البيانات الثابتة من متغيرات الشركة، والمتغير الفارغ يلغي القاعدة لحظة الإرسال.
 */
class RuleTextGuardTest extends TestCase
{
    use CapturesLogs;
    use RefreshDatabase;

    public static function rejected(): array
    {
        return [
            'price and currency' => ['السعر 350 دينار'],
            'indic digits and short currency' => ['٣٥٠ د.أ'],
            'currency first with decimals' => ['JOD 1,200.500'],
            'riyal' => ['التأشيرة 300 ر.س'],
            'dollar sign' => ['$45 per person'],
            'starts from' => ['يبدأ من 499'],
            'discount percent' => ['خصم 10%'],
            'price word two words before' => ['بسعر خاص جداً 99'],
            'payment alias' => ['حوّل على كليك nusk'],
            'cliq in latin' => ['Pay with CliQ alias nusk'],
            'iban' => ['JO71CBJO0000000000001234567890'],
            'card-like digits' => ['4111111111111111'],
            'raw phone' => ['اتصل 0790000000'],
            'raw phone with spaces' => ['call +1 650 555 1234'],
            'plain http' => ['http://x.example'],
            'internal storage link' => ['https://example.com/storage/a.png'],
            'ip link' => ['https://10.0.0.5/menu'],
            'asks for passport photo' => ['أرسل صورة جواز السفر هنا'],
            'unknown variable' => ['تواصل معنا على {company.whatsapp}'],
            'customer variable' => ['أهلاً {name}'],
            'empty' => ['   '],
        ];
    }

    #[DataProvider('rejected')]
    public function test_it_rejects(string $text): void
    {
        $this->assertNotSame([], RuleTextGuard::errors($text), $text);
    }

    public static function accepted(): array
    {
        return [
            'company variables' => ['مكتبنا في {company.address}، والدوام {company.hours}'],
            'a small number that is not a price' => ['جواز ساري 7 أشهر على الأقل'],
            'phone from the variable' => ['للتواصل: {company.phone}'],
            'documents a week before' => ['الوثائق تُسلَّم قبل السفر بأسبوع، والتأشيرة عادةً خلال 24 ساعة.'],
            'english' => ['Our office is at {company.address}. Opening hours: {company.hours}.'],
            'public https link' => ['صفحتنا: https://www.facebook.com/example'],
            'office hours range' => ['الدوام من 9 صباحاً حتى 9 مساءً'],
        ];
    }

    #[DataProvider('accepted')]
    public function test_it_accepts(string $text): void
    {
        $this->assertSame([], RuleTextGuard::errors($text), $text);
    }

    public function test_messages_are_arabic_and_name_the_fix(): void
    {
        $this->assertSame(['لا رقم هاتف مكتوباً في النص: استعمل المتغير {company.phone}.'], RuleTextGuard::errors('اتصل 0790000000'));
        $this->assertStringContainsString('{company.address}', RuleTextGuard::errors('أهلاً {name}')[0]);
        $this->assertSame(['النص أطول من 1024 حرفاً.'], RuleTextGuard::errors(str_repeat('ا', 1025)));
    }

    private function rule(array $attrs = []): WaAutoRule
    {
        return WaAutoRule::create($attrs + ['name' => 'الموقع', 'match_type' => 'any_of', 'patterns' => ['الموقع'],
            'reply_text_ar' => 'مكتبنا في {company.address}، والدوام {company.hours}', 'reply_text_en' => null]);
    }

    public function test_render_fills_company_variables_and_picks_the_customer_language(): void
    {
        Setting::set('company_address', 'عمّان، الشميساني');
        Setting::set('company_hours', 'السبت إلى الخميس 9–9');
        $rule = $this->rule(['reply_text_en' => 'Our office: {company.address}']);

        $this->assertSame('مكتبنا في عمّان، الشميساني، والدوام السبت إلى الخميس 9–9', RuleTextGuard::render($rule, false));
        $this->assertSame('Our office: عمّان، الشميساني', RuleTextGuard::render($rule, true));
        $this->assertNull(RuleTextGuard::render($this->rule(), true), 'بلا نص إنجليزي لا رد عربي لمن كتب بالإنجليزية');
    }

    public function test_an_empty_variable_cancels_the_rule_with_one_log_line_a_day(): void
    {
        $this->captureLogs();
        Setting::set('company_address', 'عمّان');
        $rule = $this->rule();

        $this->assertNull(RuleTextGuard::render($rule, false));
        $this->assertNull(RuleTextGuard::render($rule, false));

        $this->assertSame(1, collect($this->logged)->where('message', 'auto_rule_variable_empty')->count());
        $this->assertLogged('error', 'auto_rule_variable_empty', ['rule' => $rule->id]);
    }

    public function test_a_rule_saved_before_the_guard_tightened_is_stopped_at_send_time(): void
    {
        $rule = $this->rule(['reply_text_ar' => 'السعر 350 دينار للفرد']);

        $this->assertNull(RuleTextGuard::render($rule, false));
    }
}
