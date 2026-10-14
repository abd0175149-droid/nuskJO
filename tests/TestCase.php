<?php

namespace Tests;

use App\Events\NewNotification;
use App\Jobs\SendPushNotification;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

/**
 * أساس كل الاختبارات.
 *
 * - لا طلب HTTP يخرج من الاختبار: كل نداء لميتا أو للنموذج يجب أن يُزيَّف بـ Http::fake().
 * - إشعارات FCM والبث اللحظي معزولة.
 *
 * قاعدة: كل اختبار يتحقق إيجابياً (Http::assertSent، أو حالة WaMessage، أو Queue::assertPushed...)،
 * لأن الكود يبتلع الاستثناءات في مواضع عدة، فطلب نُسي تزييفه قد يُمنع بصمت ويبدو الاختبار ناجحاً.
 */
abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->withoutVite();
        Bus::fake([SendPushNotification::class]);
        Event::fake([NewNotification::class]);
    }
}
