<?php

namespace Tests\Unit;

use App\Enums\AuditAction;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * بوابة تغطية سجل التدقيق (E09.14): كل فعل في التعداد يُكتب فعلاً في الكود أو يُذكر سبب غيابه،
 * ولا كتابة بنص حر، والقيم تتسع لعمودها، ولكل فعل تسمية وفئة.
 */
class AuditCoverageTest extends TestCase
{
    /** @return array<string, string> مسار => محتوى، لملفات app/ وroutes/ عدا التعداد نفسه */
    private static function sources(): array
    {
        $root = dirname(__DIR__, 2);
        $out = [];
        foreach (['app', 'routes'] as $dir) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$root}/{$dir}")) as $file) {
                $path = $file->getPathname();
                if (str_ends_with($path, '.php') && !str_ends_with($path, 'app/Enums/AuditAction.php')) {
                    $out[$path] = file_get_contents($path);
                }
            }
        }

        return $out;
    }

    public function test_every_case_is_emitted_or_explained_and_never_both(): void
    {
        // الحالات وحدها: لا AuditAction::class ولا نداء دالة (tryFrom، cases)
        preg_match_all('/AuditAction::(\w+)\b(?!\s*\()/', implode("\n", self::sources()), $m);
        $emitted = array_values(array_diff(array_unique($m[1]), ['class']));
        $unemitted = AuditAction::INTENTIONALLY_UNEMITTED;

        foreach (AuditAction::cases() as $case) {
            $isEmitted = in_array($case->name, $emitted, true);
            $isExplained = array_key_exists($case->name, $unemitted);
            $this->assertTrue($isEmitted || $isExplained, "AuditAction::{$case->name} لا يُكتب في أي مكان ولا سبب له في INTENTIONALLY_UNEMITTED");
            $this->assertFalse($isEmitted && $isExplained, "AuditAction::{$case->name} يُكتب، فاحذفه من INTENTIONALLY_UNEMITTED");
        }
        foreach (array_diff($emitted, array_map(fn ($c) => $c->name, AuditAction::cases())) as $unknown) {
            $this->fail("AuditAction::{$unknown} غير موجود في التعداد");
        }
    }

    public function test_no_audit_write_uses_a_free_text_action(): void
    {
        foreach (self::sources() as $path => $code) {
            $this->assertDoesNotMatchRegularExpression('/AuditLog::(log|safe|sampled)\(\s*[\'"]/', $code, "{$path}: فعل بنص حر بدل AuditAction");
        }
    }

    public function test_values_fit_the_column_and_every_case_has_a_label_and_category(): void
    {
        foreach (AuditAction::cases() as $case) {
            $this->assertLessThanOrEqual(30, strlen($case->value), $case->name);
            $this->assertNotSame('', $case->label(), $case->name);
            $this->assertContains($case->category(), ['money', 'access', 'config', 'whatsapp', 'agent', 'meta', 'noise', 'system'], $case->name);
            $case->severity();
        }
        $this->assertSame(count(AuditAction::cases()), count(array_unique(array_map(fn ($c) => $c->value, AuditAction::cases()))), 'قيم مكررة');
    }
}
