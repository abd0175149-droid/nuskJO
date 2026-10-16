<?php

namespace Tests\Feature\WhatsApp;

use App\Services\WhatsApp\AdminTools;
use App\Services\WhatsApp\BotTools;
use Tests\TestCase;

/**
 * لا أدوات إرسال عامة للنموذج (E32.11، C109): الإرسال قرار بشري من الصندوق أو مسار يبنيه الكود. أداة جديدة اسمها يبدأ
 * بـ send_ أو forward_ أو reply_ أو react_ تفشل هنا ما لم تُضف إلى قائمة السماح بقرار صريح (send_office_location في E33).
 */
class NoGenericSendToolsTest extends TestCase
{
    // E33: دبوس المكتب وبطاقة الاتصال من الجدولين المعتمدين وحدهما (مطفأتان افتراضياً، وبلا وسائط حرة)
    private const ALLOWED = ['send_offer_card', 'send_office_location', 'send_contact_card'];

    public function test_the_models_have_no_generic_send_tools(): void
    {
        $names = [...array_keys(BotTools::TOOLS), ...array_keys(AdminTools::TOOLS)];
        $this->assertNotEmpty($names);

        $offenders = array_values(array_filter($names, fn (string $n) => preg_match('/^(send|forward|reply|react)_/', $n) === 1 && !in_array($n, self::ALLOWED, true)));

        $this->assertSame([], $offenders, 'أداة إرسال عامة للنموذج');
        $this->assertContains('send_offer_card', $names);
    }

    public function test_the_rule_is_written_at_the_top_of_the_bot_tools(): void
    {
        $this->assertStringContainsString('لا أدوات إرسال عامة للنموذج', (string) file_get_contents(app_path('Services/WhatsApp/BotTools.php')));
    }
}
