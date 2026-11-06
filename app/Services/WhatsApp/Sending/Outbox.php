<?php

namespace App\Services\WhatsApp\Sending;

use App\Models\WaOutbox;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * الكتابة الوحيدة في wa_outbox: upsert بالمفتاح send_key، فإعادة الجدولة تحرّك الموعد والنص ما دام الصف مفتوحاً،
 * والمرسَل أو الملغى لا يُمس. الإلغاء بالمصدر أو بالمفتاح.
 */
final class Outbox
{
    /**
     * @param  array{channel_id?: ?int, conversation_id?: ?int, client_id?: ?int, phone?: ?string, type_key: string, lane?: string,
     *   payload: array, priority?: int, due_at?: mixed, expires_at?: mixed, source_type?: ?string, source_id?: ?int, is_test?: bool,
     *   status?: string, created_by?: ?int}  $row
     */
    public static function put(string $sendKey, array $row): WaOutbox
    {
        $sendKey = mb_substr($sendKey, 0, 120);
        $existing = WaOutbox::where('send_key', $sendKey)->first();
        if ($existing !== null) {
            if ($existing->isOpen()) {
                $existing->fill(array_intersect_key($row, array_flip(['channel_id', 'conversation_id', 'client_id', 'phone', 'payload', 'priority', 'due_at', 'expires_at', 'status'])))
                    ->forceFill(['defer_reason' => null])->save();
            }

            return $existing;
        }
        try {
            return WaOutbox::create($row + [
                'send_key' => $sendKey, 'lane' => 'system', 'priority' => 5, 'status' => WaOutbox::PENDING, 'due_at' => now(), 'attempts' => 0,
            ] + ['send_key' => $sendKey]);
        } catch (UniqueConstraintViolationException) {
            return WaOutbox::where('send_key', $sendKey)->firstOrFail();
        }
    }

    public static function cancel(string $sendKey, string $reason): int
    {
        return WaOutbox::where('send_key', $sendKey)->whereIn('status', WaOutbox::OPEN)
            ->update(['status' => WaOutbox::CANCELLED, 'defer_reason' => mb_substr($reason, 0, 160), 'updated_at' => now()]);
    }

    /** يلغي كل المفتوح لمصدر (فاتورة رُفضت أو حُذفت)، أو لأنواع منه إن حُددت */
    public static function cancelFor(string $sourceType, int $sourceId, string $reason, ?array $typeKeys = null): int
    {
        return WaOutbox::where('source_type', $sourceType)->where('source_id', $sourceId)->whereIn('status', WaOutbox::OPEN)
            ->when($typeKeys !== null, fn ($q) => $q->whereIn('type_key', $typeKeys))
            ->update(['status' => WaOutbox::CANCELLED, 'defer_reason' => mb_substr($reason, 0, 160), 'updated_at' => now()]);
    }
}
