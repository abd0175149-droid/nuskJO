<?php

namespace App\Listeners\WhatsApp;

use App\Services\WhatsApp\WaLog;
use App\Support\FeatureFlags;

/**
 * أساس مستمعي ناقل E21: متزامنون (لا ShouldQueue: العامل الواحد يخدم ردود البوت)، يعملون بعد اعتماد المعاملة،
 * ولا يعملون إلا والعلم wa_event_bus مفعّل. fail-open: استثناء المستمع سطر wa.listener_failed باسم صنفه
 * والمعرّفات وحدها، بلا رسالة الاستثناء ولا الحمولة. idempotent: الأحداث لا تُطلق إلا لتغيّر فعلي، ومعها مفاتيح Cache::add.
 * أصناف مجردة لا يلتقطها اكتشاف المستمعين.
 */
abstract class BusListener
{
    protected function guarded(object $event, \Closure $work): void
    {
        try {
            if (!FeatureFlags::on('wa_event_bus')) {
                return;
            }
            $work();
        } catch (\Throwable $e) {
            WaLog::error('wa.listener_failed', ['listener' => class_basename(static::class), 'event' => class_basename($event), 'type' => class_basename($e)]
                + array_filter(['conv_id' => $event->conversationId ?? null, 'message_id' => $event->messageId ?? null], fn ($v) => $v !== null));
        }
    }
}
