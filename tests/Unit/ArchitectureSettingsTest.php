<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * حدود طبقة الإعدادات (E10): env() داخل config/ وحدها (يعمل بعد config:cache)، وكل كتابة على صف إعدادات
 * البوت عبر SettingsWriter (يرفع النسخة ويدقّق).
 */
class ArchitectureSettingsTest extends TestCase
{
    /** @return array<string, string> */
    private static function php(string ...$dirs): array
    {
        $root = dirname(__DIR__, 2);
        $out = [];
        foreach ($dirs as $dir) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$root}/{$dir}")) as $file) {
                if (str_ends_with($file->getPathname(), '.php')) {
                    $out[substr($file->getPathname(), strlen($root) + 1)] = file_get_contents($file->getPathname());
                }
            }
        }

        return $out;
    }

    public function test_env_is_read_only_inside_config(): void
    {
        foreach (self::php('app', 'routes') as $path => $code) {
            $lines = preg_grep('/^(?!\s*(\/\/|\*|\/\*)).*(?<![\w>:$])env\(/', explode("\n", $code));
            // الاستثناء الوحيد: متغير لأمر واحد على سطر الأوامر (AppServiceProvider، مشروح في مكانه)
            $lines = array_filter($lines, fn ($l) => !str_contains($l, "env('ALLOW_MIGRATE_WITHOUT_BACKUP')"));
            $this->assertSame([], array_values($lines), "{$path}: env() خارج config/");
        }
    }

    public function test_bot_settings_are_written_only_through_the_settings_writer(): void
    {
        foreach (self::php('app', 'routes') as $path => $code) {
            if (str_ends_with($path, 'SettingsWriter.php') || !str_contains($code, 'WaBotSetting')) {
                continue;
            }
            $this->assertDoesNotMatchRegularExpression('/WaBotSetting::current\(\)\s*->\s*(update|save|forceFill|fill|increment|decrement)\(/', $code, $path);
            $this->assertDoesNotMatchRegularExpression('/\$(settings|setting|s|current)\s*->\s*(update|save|increment|decrement)\(/', $code, $path);
            $this->assertDoesNotMatchRegularExpression("/DB::table\\('wa_bot_settings'\\)\\s*->[^;]*->\\s*update\\(/", $code, $path);
        }
    }
}
