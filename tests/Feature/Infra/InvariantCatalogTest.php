<?php

namespace Tests\Feature\Infra;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use Tests\Invariants;
use Tests\TestCase;

/**
 * كل ثابت في tests/Invariants.php له دالة اختبار واحدة على الأقل تحمل #[Group('INV-xx')]،
 * ولا يستعمل اختبار معرّفاً غير موجود في الكتالوج.
 *
 * يقرأ المجموعات بالانعكاس من كل ملفات *Test.php تحت tests/، على مستوى الدالة والصنف.
 */
class InvariantCatalogTest extends TestCase
{
    public function test_catalog_ids_are_contiguous_each_with_rule_and_why(): void
    {
        // متتالية من INV-01 بلا ثغرات؛ الثابت لا يُحذف ولا يُعاد ترقيمه (الاختبارات تشير إليه برقمه)
        $this->assertGreaterThanOrEqual(17, count(Invariants::ids()));
        $expected = array_map(fn (int $n) => sprintf('INV-%02d', $n), range(1, count(Invariants::ids())));

        $this->assertSame($expected, Invariants::ids());

        foreach (Invariants::CATALOG as $id => $entry) {
            $this->assertNotSame('', trim($entry['rule'] ?? ''), "{$id} بلا rule");
            $this->assertNotSame('', trim($entry['why'] ?? ''), "{$id} بلا why (لماذا الإصلاح الظاهر خاطئ)");
        }
    }

    public function test_every_invariant_has_at_least_one_test_in_its_group(): void
    {
        $groups = $this->invariantGroups();

        $missing = array_values(array_diff(Invariants::ids(), Invariants::pendingIds(), array_keys($groups)));

        $this->assertSame([], $missing, 'ثوابت بلا أي دالة اختبار تحمل مجموعتها: '.implode(', ', $missing));
    }

    public function test_pending_invariants_have_no_tests_yet(): void
    {
        // ثابت «معلّق» صار له اختبار: احذف مفتاح pending من الكتالوج حتى لا يبقى مستثنى بلا داعٍ
        $tested = array_values(array_intersect(Invariants::pendingIds(), array_keys($this->invariantGroups())));

        $this->assertSame([], $tested, 'ثوابت معلّمة pending ولها اختبارات الآن: '.implode(', ', $tested));
    }

    public function test_no_test_uses_an_invariant_id_missing_from_the_catalog(): void
    {
        $unknown = array_values(array_diff(array_keys($this->invariantGroups()), Invariants::ids()));

        $this->assertSame([], $unknown, 'مجموعات INV غير موجودة في tests/Invariants.php: '.implode(', ', $unknown));
    }

    /**
     * @return array<string, list<string>> معرّف الثابت => دوال الاختبار التي تحمله
     */
    private function invariantGroups(): array
    {
        $found = [];

        foreach ($this->discoverTestClasses() as $class) {
            $ref = new ReflectionClass($class);
            if ($ref->isAbstract()) {
                continue;
            }

            $classGroups = $this->groupsOf($ref->getAttributes(Group::class));

            foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if (!$this->isTestMethod($method)) {
                    continue;
                }

                $groups = array_merge($classGroups, $this->groupsOf($method->getAttributes(Group::class)));
                foreach ($groups as $group) {
                    if (preg_match('/^INV-\d+$/', $group)) {
                        $found[$group][] = $class.'::'.$method->getName();
                    }
                }
            }
        }

        ksort($found);

        return $found;
    }

    /** @return list<class-string> */
    private function discoverTestClasses(): array
    {
        $root = base_path('tests');
        $classes = [];

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!str_ends_with($file->getFilename(), 'Test.php')) {
                continue;
            }

            $class = $this->classInFile($file->getPathname());
            if ($class !== null && class_exists($class)) {
                $classes[] = $class;
            }
        }

        sort($classes);

        return $classes;
    }

    /** اسم الصنف من namespace وclass في الملف (لا يُفترض تطابقه مع المسار) */
    private function classInFile(string $path): ?string
    {
        $code = file_get_contents($path);

        if (!preg_match('/^\s*namespace\s+([^;]+);/m', $code, $ns)
            || !preg_match('/^\s*(?:final\s+|abstract\s+|readonly\s+)*class\s+(\w+)/m', $code, $cls)) {
            return null;
        }

        return trim($ns[1]).'\\'.$cls[1];
    }

    private function isTestMethod(ReflectionMethod $method): bool
    {
        return str_starts_with($method->getName(), 'test') || $method->getAttributes(Test::class) !== [];
    }

    /** @return list<string> */
    private function groupsOf(array $attributes): array
    {
        return array_map(fn ($a) => $a->newInstance()->name(), $attributes);
    }
}
