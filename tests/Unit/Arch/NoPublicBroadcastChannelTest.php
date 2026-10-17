<?php

namespace Tests\Unit\Arch;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * لا قناة بث عامة في التطبيق (E28): كل حدث بث على قناة خاصة يفوّضها /broadcasting/auth، ولا اشتراك عام في الواجهة.
 */
class NoPublicBroadcastChannelTest extends TestCase
{
    /** @return array<string, string> */
    private static function files(string $dir, array $ext, array $skip = []): array
    {
        $root = dirname(__DIR__, 3);
        $out = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$root}/{$dir}", RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            $path = substr($file->getPathname(), strlen($root) + 1);
            if (!in_array(pathinfo($path, PATHINFO_EXTENSION), $ext, true)) {
                continue;
            }
            foreach ($skip as $prefix) {
                if (str_starts_with($path, $prefix)) {
                    continue 2;
                }
            }
            $out[$path] = file_get_contents($file->getPathname());
        }

        return $out;
    }

    public function test_events_broadcast_on_private_channels_only(): void
    {
        $events = self::files('app/Events', ['php']);
        $this->assertNotEmpty($events);
        foreach ($events as $path => $code) {
            $this->assertDoesNotMatchRegularExpression('/new\s+(\\\\?Illuminate\\\\Broadcasting\\\\)?Channel\s*\(/', $code, "{$path}: قناة عامة");
        }
    }

    public function test_the_front_end_never_subscribes_to_a_public_channel(): void
    {
        foreach (self::files('resources/js', ['js', 'vue'], ['resources/js/vendor/']) as $path => $code) {
            $this->assertDoesNotMatchRegularExpression('/Echo\s*\.\s*channel\s*\(/', $code, "{$path}: اشتراك في قناة عامة");
            $this->assertStringNotContainsString('nusuk-updates', $code, "{$path}: القناة العامة القديمة");
        }
    }

    public function test_only_private_channels_are_registered(): void
    {
        $code = file_get_contents(dirname(__DIR__, 3).'/routes/channels.php');
        preg_match_all("/Broadcast::channel\('([^']+)'/", $code, $m);
        $this->assertEqualsCanonicalizing(['user.{id}', 'staff'], $m[1]);
    }
}
