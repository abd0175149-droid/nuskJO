<?php

namespace Tests\Unit\Docs;

use Tests\TestCase;

/**
 * كل مسار في الكود يذكره التوثيق موجود فعلاً (E25): في docs/**‏/*.md وREADME.md وSECURITY.md، كل نص بين علامتي `
 * يبدأ بـ app/ أو config/ أو routes/ أو resources/ أو database/ أو docker/ أو scripts/ أو tests/. ملف نُقل أو حُذف
 * يُفشل الاختبار باسم الوثيقة والسطر.
 */
class DocPathsExistTest extends TestCase
{
    public const PATH = '/`((?:app|config|routes|resources|database|docker|scripts|tests)\/[\w.\/-]+)`/';

    /** مجلدات تُنشأ عند التشغيل على الخادم ولا تُتتبَّع في المستودع */
    public const RUNTIME = ['database/data/'];

    /** @return list<string> */
    private function documents(): array
    {
        $files = ['README.md', 'SECURITY.md'];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('docs'), \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->getExtension() === 'md') {
                $files[] = ltrim(str_replace(base_path(), '', $file->getPathname()), '/');
            }
        }

        return array_values(array_filter($files, fn ($f) => is_file(base_path($f))));
    }

    public function test_every_code_path_named_in_the_docs_exists(): void
    {
        $missing = [];
        $count = 0;
        foreach ($this->documents() as $doc) {
            foreach (explode("\n", (string) file_get_contents(base_path($doc))) as $i => $line) {
                preg_match_all(self::PATH, $line, $m);
                foreach ($m[1] as $path) {
                    $count++;
                    if (!in_array($path, self::RUNTIME, true) && !file_exists(base_path(rtrim($path, '/.')))) {
                        $missing[] = "{$doc}:".($i + 1).": {$path}";
                    }
                }
            }
        }

        $this->assertGreaterThan(20, $count, 'الفحص لا يجد مسارات أصلاً');
        $this->assertSame([], $missing, "مسارات في التوثيق لا توجد في المستودع:\n".implode("\n", $missing));
    }

    public function test_the_pattern_reads_code_paths_only(): void
    {
        preg_match_all(self::PATH, 'انظر `app/Services/WhatsApp/Nope.php` و`storage/logs` و`docs/RUNBOOK.md` و`app/<ملف>`', $m);

        $this->assertSame(['app/Services/WhatsApp/Nope.php'], $m[1]);
        $this->assertFileDoesNotExist(base_path($m[1][0]));
    }
}
