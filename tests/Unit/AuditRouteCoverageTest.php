<?php

namespace Tests\Unit;

use App\Enums\AuditAction;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * كل مسار كتابة مصنَّف في config/audit_routes.php (E09.14): فعل تدقيق يكتبه، أو 'none: السبب'.
 * مسار جديد بلا تصنيف يُفشل الاختبار، فلا تُضاف كتابة دون قرار صريح في تدقيقها.
 */
class AuditRouteCoverageTest extends TestCase
{
    /** @return array<string, string> مفتاح المسار => الطرق */
    private function writeRoutes(): array
    {
        $out = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $methods = array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']);
            if (!$methods) {
                continue;
            }
            $action = $route->getActionName();
            $key = $action === 'Closure' ? reset($methods).' '.$route->uri() : str_replace('App\\Http\\Controllers\\', '', ltrim($action, '\\'));
            $out[$key] = implode('|', $methods).' '.$route->uri();
        }

        return $out;
    }

    public function test_every_write_route_is_classified_and_no_classification_is_stale(): void
    {
        $map = config('audit_routes');
        $routes = $this->writeRoutes();

        $missing = array_diff_key($routes, $map);
        $this->assertSame([], $missing, "مسارات كتابة بلا تصنيف في config/audit_routes.php:\n".implode("\n", array_map(fn ($k, $v) => "'{$k}' ({$v})", array_keys($missing), $missing)));
        $this->assertSame([], array_keys(array_diff_key($map, $routes)), 'تصنيفات لمسارات لم تعد موجودة');
    }

    public function test_each_classification_is_a_real_action_or_a_reasoned_none(): void
    {
        $values = array_map(fn (AuditAction $a) => $a->value, AuditAction::cases());

        foreach (config('audit_routes') as $route => $class) {
            if (str_starts_with($class, 'none:')) {
                $this->assertGreaterThanOrEqual(4, mb_strlen(trim(substr($class, 5))), "{$route}: 'none:' بلا سبب");
            } else {
                $this->assertContains($class, $values, "{$route}: '{$class}' ليس فعلاً في AuditAction");
            }
        }
    }
}
