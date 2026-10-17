<?php

namespace Tests\Unit;

use App\Services\Retention\ConversationCacheKeys;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * كل مفتاح كاش يحمل معرّف محادثة مذكور في ConversationCacheKeys (E13.5)، وإلا بقي أثر بعد الحذف.
 * يبحث عن نصوص مثل "wa:debounce:{$convId}" أو "wa:rate:".$conv->id في app/ وroutes/.
 */
class ConversationCacheKeysTest extends TestCase
{
    private const CONV_VARS = '(\$conv->id|\$c->id|\$conversation->id|\$convId|\$conversationId|\$this->conversationId|\$byPhone->id|\$id)';

    public function test_every_conversation_scoped_cache_key_is_registered(): void
    {
        $root = dirname(__DIR__, 2);
        $templates = array_merge(ConversationCacheKeys::EXACT, ConversationCacheKeys::PATTERNS);
        $found = [];
        foreach (['app', 'routes'] as $dir) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$root}/{$dir}"));
            foreach ($it as $file) {
                if ($file->getExtension() !== 'php' || str_contains($file->getPathname(), 'ConversationCacheKeys.php')) {
                    continue;
                }
                $code = file_get_contents($file->getPathname());
                // "wa:x:{$conv->id}…" داخل نص بعلامتي تنصيص مزدوجتين
                preg_match_all('/"((?:wa|audit):[a-z:_-]*)\{'.self::CONV_VARS.'\}/', $code, $m1);
                // 'wa:x:' . $conv->id
                preg_match_all("/'((?:wa|audit):[a-z:_-]*)'\s*\.\s*".self::CONV_VARS.'/', $code, $m2);
                foreach (array_merge($m1[1], $m2[1]) as $prefix) {
                    $found[$prefix] = $file->getFilename();
                }
            }
        }
        $this->assertNotEmpty($found);

        foreach ($found as $prefix => $file) {
            $covered = (bool) array_filter($templates, fn ($t) => str_starts_with($t, $prefix.'{id}') || str_starts_with($t, $prefix.'%'));
            $this->assertTrue($covered, "مفتاح «{$prefix}…» في {$file} يحمل معرّف محادثة وليس في ConversationCacheKeys");
        }
    }
}
