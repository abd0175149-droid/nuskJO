<?php

namespace Tests\Feature\WhatsApp;

use App\Models\WaConversation;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * أي مسار جديد يحمل محادثة يجب أن يمر بالربط المقيّد وأن يُختبر في ConversationScopeTest.
 * معامل WaConversation باسم آخر يُربط ضمنياً فيتجاوز Route::bind('conversation').
 */
#[Group('INV-17')]
class ConversationRouteCoverageTest extends TestCase
{
    public function test_every_conversation_route_has_a_scope_case(): void
    {
        $found = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (!str_contains($route->uri(), '{conversation}')) {
                continue;
            }
            $action = trim(Str::after($route->uri(), '{conversation}'), '/') ?: '(show)';
            $found[$action] = $route->methods();
        }

        $this->assertEqualsCanonicalizing(array_keys(ConversationScopeTest::ROUTES), array_keys($found),
            'أضف المسار الجديد إلى ConversationScopeTest::ROUTES');
        foreach (ConversationScopeTest::ROUTES as $action => [$method]) {
            $this->assertContains($method, $found[$action], $action);
        }
    }

    public function test_controllers_receive_conversations_only_through_the_scoped_parameter(): void
    {
        $offenders = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $uses = $route->getAction('uses');
            if (!is_string($uses) || !str_contains($uses, '@')) {
                continue;
            }
            [$class, $method] = explode('@', $uses);
            if (!method_exists($class, $method)) {
                continue;
            }
            foreach ((new \ReflectionMethod($class, $method))->getParameters() as $parameter) {
                $type = $parameter->getType();
                $isConversation = $type instanceof \ReflectionNamedType && is_a($type->getName(), WaConversation::class, true);
                if ($isConversation && ($parameter->getName() !== 'conversation' || !str_contains($route->uri(), '{conversation}'))) {
                    $offenders[] = "{$route->uri()} → {$class}@{$method}(\${$parameter->getName()})";
                }
            }
        }

        $this->assertSame([], $offenders);
    }
}
