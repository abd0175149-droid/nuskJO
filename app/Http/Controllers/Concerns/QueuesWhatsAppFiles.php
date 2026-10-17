<?php

namespace App\Http\Controllers\Concerns;

use App\Enums\AuditAction;
use App\Jobs\SendWhatsAppMediaJob;
use App\Models\AuditLog;
use App\Models\WaConversation;
use App\Services\WhatsApp\Outbound\OutboundFileStore;
use App\Services\WhatsApp\Outbound\OutboundMessage;
use App\Services\WhatsApp\WaLog;
use App\Services\WhatsApp\WhatsAppChannel;
use Illuminate\Http\JsonResponse;

/**
 * حجز ملف الموظف ووضع رفعه في الطابور (E32): صف queued بالمفتاح والنسخة الخاصة، ثم SendWhatsAppMediaJob. الرد 202
 * «في الطابور» برقم الصف. المفتاح نفسه ما دام في الطابور يعيد الرد نفسه بلا صف ولا رفع ثانٍ، وبعد الإرسال 200 replayed.
 * نسخة طلبٍ لم يُحجز له صف تُحذف فوراً.
 */
trait QueuesWhatsAppFiles
{
    /**
     * @param  array{mime: string, name: string, type: string}  $info  من OutboundMediaPolicy::inspect
     * @param  array{path: string, sha256: string, size: int}  $stored  من OutboundFileStore::put
     * @param  list<string>  $warnings  تحذيرات سياسة أكّدها الموظف (تُدقَّق)
     */
    protected function queueWhatsAppFile(WaConversation $conversation, OutboundMessage $message, int $userId, string $key,
        array $info, array $stored, array $payload, array $warnings = []): JsonResponse
    {
        $res = WhatsAppChannel::reserve($conversation, $message, 'staff', $userId, [
            'send_key' => $key,
            'media' => ['mime' => $info['mime'], 'sha256' => $stored['sha256'], 'size' => $stored['size'], 'filename' => $info['name'], 'path' => $stored['path']],
            'payload' => $payload,
        ]);

        if (!$res['ok'] || !empty($res['replayed'])) {
            OutboundFileStore::delete($stored['path']);
        }
        if (!$res['ok']) {
            // المفتاح نفسه ما زال في الطابور: نتيجة الطلب الأول نفسها، بلا رفع ثانٍ
            if (($res['code'] ?? null) === 'SEND_IN_PROGRESS' && isset($res['row_id'])) {
                return response()->json(['ok' => true, 'status' => 'queued', 'row_id' => $res['row_id'], 'message_id' => null, 'replayed' => true], 202);
            }

            return response()->json($res, match (true) {
                ($res['code'] ?? null) === 'IDEMPOTENCY_MISMATCH' => 422,
                !empty($res['conflict']) => 409,
                default => 422,
            });
        }
        if (!empty($res['replayed'])) {
            return response()->json($res, 200);
        }

        SendWhatsAppMediaJob::dispatch($res['row_id'])->afterCommit();
        if ($warnings) {
            AuditLog::safe(AuditAction::WaPolicyOverride, 'wa_conversation', $conversation->id, null, ['reasons' => $warnings, 'message_id' => $res['row_id']]);
        }
        WaLog::info('wa.media.queued', ['conv_id' => $conversation->id, 'message_id' => $res['row_id'], 'type' => $info['type'],
            'mime' => $info['mime'], 'bytes' => $stored['size'], 'sha12' => substr($stored['sha256'], 0, 12)]);

        return response()->json(['ok' => true, 'status' => 'queued', 'row_id' => $res['row_id'], 'message_id' => null, 'replayed' => false], 202);
    }
}
