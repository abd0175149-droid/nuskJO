<?php

namespace Tests\Unit\Arch;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use PHPUnit\Framework\TestCase;

/**
 * حمولة أحداث الواتساب الداخلية (E21): معرّفات ورموز فقط. كل صنف في app/Events/WhatsApp نهائي، ويُطلق بعد الاعتماد،
 * وخصائصه مُرقّاة للقراءة فقط من int أو string أو bool (أو nullable منها)، وبلا اسم يدل على نص أو هاتف أو اسم.
 * ومستمعو app/Listeners/WhatsApp متزامنون: لا طابور يزاحم ردود البوت.
 */
class WhatsAppEventPayloadTest extends TestCase
{
    private const FORBIDDEN = '/body|text|phone|wa_id|waid|name|caption|title|details/i';

    /** @return list<class-string> */
    private static function classesIn(string $dir, string $namespace): array
    {
        $out = [];
        foreach (glob(dirname(__DIR__, 3)."/app/{$dir}/*.php") as $file) {
            $out[] = $namespace.'\\'.basename($file, '.php');
        }

        return $out;
    }

    public function test_there_are_six_events(): void
    {
        $this->assertEqualsCanonicalizing([
            'WaMessageStored', 'WaMessageSent', 'WaMessageFailed', 'WaMessageStatusChanged', 'ConversationHandedOff', 'ChannelHealthChanged',
        ], array_map(fn ($c) => class_basename($c), self::classesIn('Events/WhatsApp', 'App\\Events\\WhatsApp')));
    }

    public function test_every_payload_is_ids_and_codes_only(): void
    {
        foreach (self::classesIn('Events/WhatsApp', 'App\\Events\\WhatsApp') as $class) {
            $ref = new \ReflectionClass($class);
            $this->assertTrue($ref->isFinal(), "{$class} ليس final");
            $this->assertTrue($ref->implementsInterface(ShouldDispatchAfterCommit::class), "{$class} لا يُطلق بعد الاعتماد");
            $traits = $ref->getTraitNames();
            $this->assertContains(Dispatchable::class, $traits, $class);
            $this->assertNotContains(SerializesModels::class, $traits, "{$class}: لا نماذج في الحمولة");

            $props = $ref->getProperties();
            $this->assertNotEmpty($props, $class);
            foreach ($props as $prop) {
                $where = "{$class}::\${$prop->getName()}";
                $this->assertTrue($prop->isPublic() && $prop->isReadOnly() && $prop->isPromoted(), "{$where} ليست خاصية مُرقّاة للقراءة فقط");
                $type = $prop->getType();
                $this->assertInstanceOf(\ReflectionNamedType::class, $type, "{$where}: نوع واحد فقط");
                $this->assertContains($type->getName(), ['int', 'string', 'bool'], "{$where}: النوع {$type->getName()} غير مسموح");
                $this->assertDoesNotMatchRegularExpression(self::FORBIDDEN, $prop->getName(), "{$where}: اسم يدل على نص أو هاتف أو اسم");
            }
        }
    }

    public function test_listeners_are_synchronous(): void
    {
        $listeners = self::classesIn('Listeners/WhatsApp', 'App\\Listeners\\WhatsApp');
        $this->assertNotEmpty($listeners);
        foreach ($listeners as $class) {
            $this->assertFalse((new \ReflectionClass($class))->implementsInterface(ShouldQueue::class), "{$class} في الطابور");
        }
    }
}
