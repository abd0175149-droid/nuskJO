<?php

namespace App\Services\WhatsApp;

use Illuminate\Support\Facades\DB;

/**
 * إطلاق أحداث app/Events/WhatsApp (E21): بعد اعتماد المعاملة الجارية وحدها (rollback يُسقطه)، وبلا أثر على من أطلقه.
 * المستمع fail-open: استثناؤه يُسجَّل باسم صنفه ولا يصل إلى الويبهوك ولا إلى الإرسال ولا إلى جدولة رد البوت.
 * والأقفال والبوابات تبقى متزامنة ومغلقة عند الفشل في مواضعها: لا حدث قبل dispatch يستطيع منع إرسال أو تحويله،
 * ولا يُقرأ ما يعيده مستمع. رد البوت يبقى استدعاء ProcessWhatsAppReply::schedule الصريح، لا مستمعاً.
 */
final class EventBus
{
    public static function emit(object $event): void
    {
        try {
            DB::afterCommit(function () use ($event) {
                try {
                    event($event);
                } catch (\Throwable $e) {
                    self::failed($event, $e);
                }
            });
        } catch (\Throwable $e) {
            self::failed($event, $e);
        }
    }

    private static function failed(object $event, \Throwable $e): void
    {
        WaLog::error('wa.listener_failed', ['event' => class_basename($event), 'type' => class_basename($e)]
            + array_filter(['conv_id' => $event->conversationId ?? null, 'message_id' => $event->messageId ?? null], fn ($v) => $v !== null));
    }
}
