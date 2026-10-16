<?php

namespace Tests\Unit\Arch;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * حدود E36 (رأس WhatsAppChannel): لا كتالوج ميتا ولا Flows بنقطة نهاية دون قرار مكتوب من المالك، والقوائم والأزرار
 * لا تُبنى إلا من ChoiceBuilder أو من نص ثابت في الكود، لا من نص يكتبه النموذج.
 */
class NoCatalogNoFlowEndpointTest extends TestCase
{
    /** @return array<string, string> المسار => المحتوى */
    private function files(string $dir): array
    {
        $out = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($dir), \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php') {
                $out[substr($file->getPathname(), strlen(base_path()) + 1)] = (string) file_get_contents($file->getPathname());
            }
        }

        return $out;
    }

    public function test_no_catalog_or_commerce_calls_exist_in_the_app(): void
    {
        $offenders = [];
        foreach ($this->files('app') as $path => $code) {
            foreach (['catalog_id', 'product_retailer_id', 'whatsapp_commerce_settings', 'product_list', 'catalog_message'] as $needle) {
                if (str_contains($code, $needle)) {
                    $offenders[] = "{$path}: {$needle}";
                }
            }
        }

        $this->assertSame([], $offenders);
    }

    public function test_no_flow_endpoint_exists(): void
    {
        $offenders = [];
        foreach ([...$this->files('app'), ...$this->files('routes')] as $path => $code) {
            if (str_contains($code, 'data_exchange') || str_contains($code, 'endpoint_uri') || str_contains($code, 'flow_name')) {
                $offenders[] = $path;
            }
        }
        $this->assertSame([], $offenders);

        $routes = collect(Route::getRoutes()->getRoutes())->map(fn ($r) => $r->uri())
            ->filter(fn (string $uri) => preg_match('/(^|\/)flows?(\/|-|$)|flow-endpoint/i', $uri) === 1)->values()->all();
        $this->assertSame([], $routes, 'مسار عام لنقطة نهاية Flow');
    }

    public function test_lists_and_buttons_come_from_the_choice_builder_or_fixed_code(): void
    {
        $callers = [];
        foreach ($this->files('app') as $path => $code) {
            if (preg_match('/WhatsAppChannel::send(?:List|Buttons|Flow)\(/', $code)) {
                $callers[] = $path;
            }
        }
        sort($callers);

        // البوت عبر InteractiveReply (خيارات ChoiceBuilder)، وزرّا المساعد الإداري وموافقة الملفات والإرسال التجريبي (E23) نصوص ثابتة
        $this->assertSame([
            'app/Services/WhatsApp/AdminTools.php',
            'app/Services/WhatsApp/InteractiveReply.php',
            'app/Services/WhatsApp/Media/MediaConsent.php',
            'app/Services/WhatsApp/TestSend.php',
        ], $callers);

        $bot = (string) file_get_contents(app_path('Services/WhatsApp/BotTools.php'));
        foreach (['sendList(', 'sendButtons(', 'sendFlow(', 'InteractiveTypes::'] as $needle) {
            $this->assertStringNotContainsString($needle, $bot, 'أدوات البوت لا تبني رسالة تفاعلية بنفسها');
        }

        $factories = array_keys(array_filter($this->files('app'), fn (string $code) => str_contains($code, 'InteractiveTypes::buttons(')
            || str_contains($code, 'InteractiveTypes::list(') || str_contains($code, 'InteractiveTypes::flow(')));
        $this->assertSame(['app/Services/WhatsApp/WhatsAppChannel.php'], $factories);
    }

    public function test_the_channel_states_the_boundary(): void
    {
        $this->assertStringContainsString('الكتالوج وFlows بنقطة نهاية خارج النطاق دون قرار مكتوب من المالك',
            (string) file_get_contents(app_path('Services/WhatsApp/WhatsAppChannel.php')));
    }
}
