<?php

namespace Tests\Unit\Docs;

use Tests\TestCase;

/**
 * وثائق مكوّنات طبقة الواتساب (E25) بالقالب الثابت: الحالة، ثم لماذا وُجد، ثم القواعد الثابتة، ثم نموذج التهديد العام،
 * ثم ما لا تضمنه الطبقة، ثم خارطة الطريق. وكل إحالة docs/… في تعليقات الكود إلى وثيقة موجودة، وكل وثيقة في docs/
 * مذكورة في فهرس docs/README.md (وثيقة جديدة سطر واحد في الفهرس).
 */
class ComponentDocsTest extends TestCase
{
    public const COMPONENTS = [
        'channel.md', 'webhook.md', 'inbox.md', 'bot-engine.md', 'bot-tools.md', 'handoff.md', 'topic-routing.md',
        'admin-assistant.md', 'offers-costing.md', 'settings-secrets.md', 'health-monitoring.md',
    ];

    /** وثائق ملحمة لم تُدمج في هذا الفرع بعد، يحيل إليها الكود. تُحذف من هنا بعد دمجها */
    public const PENDING = ['docs/ops/load-budget.md' => 'E24', 'docs/whatsapp/ops-alerts.md' => 'E24'];

    public const SECTIONS = ['## الحالة', '## لماذا وُجد', '## القواعد الثابتة', '## نموذج التهديد (عام)', '## ما لا تضمنه الطبقة', '## خارطة الطريق'];

    public function test_every_component_document_follows_the_template(): void
    {
        foreach (self::COMPONENTS as $file) {
            $path = base_path("docs/whatsapp/{$file}");
            $this->assertFileExists($path);
            $doc = (string) file_get_contents($path);

            $offset = 0;
            foreach (self::SECTIONS as $heading) {
                $at = mb_strpos($doc, "\n{$heading}\n", $offset);
                $this->assertNotFalse($at, "docs/whatsapp/{$file}: القسم «{$heading}» غائب أو في غير ترتيبه");
                $offset = $at;
            }
            $this->assertStringContainsString('| البند | الحالة | الملف | الملحمة |', $doc, "docs/whatsapp/{$file}: جدول الحالة بأعمدته الأربعة");
        }
    }

    public function test_every_doc_reference_in_the_code_exists(): void
    {
        $missing = [];
        foreach (['app', 'config', 'routes'] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($dir), \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                preg_match_all('#\bdocs/[\w./-]+\.md\b#', (string) file_get_contents($file->getPathname()), $m);
                foreach (array_unique($m[0]) as $ref) {
                    if (!is_file(base_path($ref)) && !isset(self::PENDING[$ref])) {
                        $missing[] = str_replace(base_path().'/', '', $file->getPathname()).": {$ref}";
                    }
                }
            }
        }

        $this->assertSame([], $missing, "إحالات في الكود إلى وثائق غير موجودة:\n".implode("\n", $missing));
    }

    public function test_the_index_lists_every_document(): void
    {
        $index = (string) file_get_contents(base_path('docs/README.md'));
        $missing = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('docs'), \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $relative = ltrim(str_replace(base_path('docs'), '', $file->getPathname()), '/');
            if ($file->getExtension() === 'md' && $relative !== 'README.md' && !str_contains($index, "]({$relative})")) {
                $missing[] = "docs/{$relative}";
            }
        }

        $this->assertSame([], $missing, "وثائق بلا سطر في فهرس docs/README.md (أضف «- [العنوان](المسار) — سطر»):\n".implode("\n", $missing));
    }
}
