<?php

namespace Tests\Unit\Arch;

use App\Services\WhatsApp\WhatsAppChannel;
use Tests\TestCase;

/**
 * البوت لا يتفاعل (E33): sendReaction للموظف وحده من الصندوق. لا يستدعيها BotTools ولا AdminTools ولا BotEngine ولا أي
 * وظيفة أو أمر، فلا يرسل النموذج تفاعلاً ولا يملك أداة له.
 */
class NoBotReactionsTest extends TestCase
{
    public function test_only_the_inbox_controller_calls_send_reaction(): void
    {
        $callers = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() === 'php' && str_contains((string) file_get_contents($file->getPathname()), 'sendReaction(')) {
                $callers[] = substr($file->getPathname(), strlen(app_path()) + 1);
            }
        }
        sort($callers);

        $this->assertSame(['Http/Controllers/WhatsAppRichMessageController.php', 'Services/WhatsApp/WhatsAppChannel.php'], $callers);
        foreach (['BotTools.php', 'AdminTools.php', 'BotEngine.php'] as $file) {
            $this->assertStringNotContainsString('sendReaction', (string) file_get_contents(app_path('Services/WhatsApp/'.$file)));
        }
    }

    public function test_send_reaction_has_no_source_parameter(): void
    {
        $params = array_map(fn (\ReflectionParameter $p) => $p->getName(), (new \ReflectionMethod(WhatsAppChannel::class, 'sendReaction'))->getParameters());

        $this->assertNotContains('source', $params);
        $this->assertContains('staffId', $params);
    }
}
