<?php

namespace Tests\Feature\Docs;

use App\Console\Commands\DocsCapabilities;
use App\Services\WhatsApp\AdminTools;
use App\Services\WhatsApp\BotTools;
use App\Services\WhatsApp\WhatsAppChannel;
use App\Support\FeatureFlags;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * مصفوفة قدرات Cloud API تعكس الكود (E25): كل دالة إرسال عامة في WhatsAppChannel لها صف، وكل اسم في methods دالة
 * موجودة، وأدوات البوت والمساعد الإداري هي سجلاتهما في الكود (لا declarations المشروطة بالإعدادات)، والقرار المرفوض
 * أو المؤجل بسبب، والمخطط بملحمة، والملف المولَّد مطابق.
 */
class CapabilityMatrixTest extends TestCase
{
    private function matrix(): array
    {
        return (array) config('whatsapp_capabilities');
    }

    /** @return list<string> */
    private function rowMethods(): array
    {
        return array_values(array_unique(array_merge(...array_map(fn ($r) => $r['methods'] ?? [], $this->matrix()['rows']))));
    }

    public function test_every_public_send_method_of_the_channel_has_a_row_and_every_listed_method_exists(): void
    {
        $listed = $this->rowMethods();
        $public = collect((new \ReflectionClass(WhatsAppChannel::class))->getMethods(\ReflectionMethod::IS_PUBLIC))
            ->filter(fn (\ReflectionMethod $m) => $m->isStatic())->map(fn ($m) => $m->name)->values()->all();

        $uncovered = array_values(array_filter($public, fn ($m) => preg_match('/^(send|download|mark|block|upload)/', $m) === 1 && !in_array($m, $listed, true)));
        $this->assertSame([], $uncovered, 'دوال في WhatsAppChannel بلا صف في config/whatsapp_capabilities.php: '.implode('، ', $uncovered));

        $ghosts = array_values(array_diff($listed, $public));
        $this->assertSame([], $ghosts, 'أسماء في methods ليست دوال عامة في WhatsAppChannel: '.implode('، ', $ghosts));
    }

    public function test_tool_sections_match_the_tool_registries_in_the_code(): void
    {
        $bot = $this->matrix()['bot_tools'];
        $this->assertEqualsCanonicalizing(array_keys(BotTools::TOOLS), array_keys($bot), 'bot_tools في المصفوفة لا يطابق BotTools::TOOLS');
        foreach (BotTools::TOOLS as $name => $def) {
            $this->assertSame((bool) ($def['escalates'] ?? false), (bool) $bot[$name]['escalating'], "{$name}: escalating لا يطابق escalates في BotTools::TOOLS");
            $this->assertSame($def['flag'] ?? null, $bot[$name]['flag'] ?? null, "{$name}: علم الأداة");
        }

        $this->assertEqualsCanonicalizing(array_keys(AdminTools::TOOLS), array_keys($this->matrix()['admin_tools']), 'admin_tools في المصفوفة لا يطابق AdminTools::TOOLS');
    }

    public function test_every_row_is_complete_for_its_status(): void
    {
        $keys = [];
        foreach ($this->matrix()['rows'] as $row) {
            $key = $row['key'] ?? '?';
            $keys[] = $key;
            $this->assertArrayHasKey($row['status'] ?? '', DocsCapabilities::STATUSES, "{$key}: حالة غير معروفة");
            $this->assertNotSame('', trim((string) ($row['label'] ?? '')), "{$key}: بلا label");
            if (in_array($row['status'], ['rejected', 'deferred'], true)) {
                $this->assertNotSame('', trim((string) ($row['reason'] ?? '')), "{$key}: {$row['status']} بلا reason");
            }
            if ($row['status'] === 'rejected') {
                $this->assertNotSame('', trim((string) ($row['decided'] ?? '')), "{$key}: rejected بلا decided");
            }
            if (in_array($row['status'], ['planned', 'built_unused'], true)) {
                $this->assertMatchesRegularExpression('/^E\d{2}$/', (string) ($row['epic'] ?? ''), "{$key}: {$row['status']} بلا epic");
            }
            $this->assertMatchesRegularExpression('#^https://(developers\.facebook\.com|www\.facebook\.com/legal|whatsappbusiness\.com)/#', (string) ($row['official'] ?? ''), "{$key}: رابط official ليس من ميتا");
            if (isset($row['flag'])) {
                $this->assertArrayHasKey($row['flag'], FeatureFlags::DEFINITIONS, "{$key}: العلم {$row['flag']} غير معرّف");
            }
        }
        $this->assertSame($keys, array_values(array_unique($keys)), 'مفتاح صف مكرر');

        $byKey = collect($this->matrix()['rows'])->keyBy('key');
        foreach (['template', 'marketing_bulk', 'interactive.product', 'groups'] as $decision) {
            $this->assertSame('rejected', $byKey[$decision]['status'] ?? null, "القرار المرفوض {$decision} مسجّل بسببه");
        }
    }

    public function test_the_generated_document_is_up_to_date_and_the_check_detects_drift(): void
    {
        $this->assertSame(0, Artisan::call('docs:capabilities', ['--check' => true]), 'docs/whatsapp/capabilities.md قديم: شغّل php artisan docs:capabilities');

        $changed = config('whatsapp_capabilities');
        $changed['rows'][0]['label'] .= ' (تعديل)';
        $this->assertNotSame(DocsCapabilities::render($changed), (string) file_get_contents(base_path(DocsCapabilities::PATH)));
        config(['whatsapp_capabilities' => $changed]);
        $this->assertSame(1, Artisan::call('docs:capabilities', ['--check' => true]), '--check يعيد 1 عند الاختلاف دون كتابة');
        $this->assertStringNotContainsString('(تعديل)', (string) file_get_contents(base_path(DocsCapabilities::PATH)));
    }

    public function test_the_matrix_config_reads_no_environment_and_holds_no_account_ids(): void
    {
        $source = (string) file_get_contents(config_path('whatsapp_capabilities.php'));

        $this->assertStringNotContainsString('env(', $source);
        $this->assertDoesNotMatchRegularExpression('/\b\d{12,}\b/', $source, 'لا معرّف رقم أو حساب');
    }
}
