<?php

namespace Tests\Unit\Arch;

use App\Mcp\Attributes\IsIdempotent;
use App\Mcp\Attributes\IsReadOnly;
use App\Mcp\Servers\OwnerReportsServer;
use App\Mcp\Tools\Tool;
use Tests\TestCase;

/**
 * حارس معماري لخادم MCP (E40.9): لا شيء تحت app/Mcp يلمس جداول واتساب أو نماذجها أو نص طلب التسعير، وكل أداة
 * للقراءة وحدها (IsReadOnly وIsIdempotent) ومسجّلة في الخادم، ولا اسم أداة يوحي بالكتابة.
 */
class McpArchitectureTest extends TestCase
{
    /** @return array<string, string> المسار => الكود بلا تعليقات */
    private function mcpCode(): array
    {
        $out = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path('Mcp'), \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $code = '';
            foreach (token_get_all(file_get_contents($file->getPathname())) as $token) {
                if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $code .= is_array($token) ? $token[1] : $token;
            }
            $out[substr($file->getPathname(), strlen(base_path()) + 1)] = $code;
        }

        return $out;
    }

    public function test_nothing_under_app_mcp_touches_whatsapp_data(): void
    {
        $code = $this->mcpCode();
        $this->assertNotEmpty($code);
        foreach ($code as $path => $source) {
            foreach (['/\bWaMessage\b/', '/\bWaCustomerNote\b/', '/\bWaConversation\b/', '/[\'"]wa_\w+/', '/->details\b/', '/[\'"]details[\'"]/', '/WhatsApp\\\\/', '/withWhatsapp:\s*true/', '/withPhones:\s*true/', '/withDetails:\s*true/'] as $pattern) {
                $this->assertDoesNotMatchRegularExpression($pattern, $source, "{$path} يلمس بيانات واتساب ({$pattern})");
            }
        }
    }

    public function test_every_tool_is_read_only_idempotent_registered_and_not_named_like_a_write(): void
    {
        $tools = [];
        foreach (glob(app_path('Mcp/Tools/*.php')) as $file) {
            $class = 'App\\Mcp\\Tools\\'.basename($file, '.php');
            if ($class === Tool::class) {
                continue;
            }
            $this->assertTrue(is_subclass_of($class, Tool::class), $class);
            $this->assertTrue(Tool::has($class, IsReadOnly::class), "{$class} بلا #[IsReadOnly]");
            $this->assertTrue(Tool::has($class, IsIdempotent::class), "{$class} بلا #[IsIdempotent]");
            $name = (new $class)->name();
            $this->assertDoesNotMatchRegularExpression('/update|create|delete|send|write|set_|approve/i', $name, $class);
            $tools[] = $class;
        }

        $this->assertEqualsCanonicalizing($tools, OwnerReportsServer::TOOLS, 'كل أداة مسجّلة، ولا مسجّل بلا ملف');
        $this->assertCount(6, $tools);
    }
}
