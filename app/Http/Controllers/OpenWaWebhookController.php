<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\WaChannel;
use App\Services\WhatsApp\WaLog;
use App\Services\WhatsApp\WebhookIntake;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * webhook بوابة OpenWA لرقم واحد: POST api/openwa/webhook/{uuid}. خارج auth وCSRF ومجموعة web (routes/webhooks.php).
 * الترتيب: الحجم، ثم الرقم من uuid، ثم التوقيع بسر الرقم (X-OpenWA-Signature: sha256=HMAC)، ثم الجلسة في الحمولة تطابق
 * جلسة الرقم، ثم منع التكرار بمفتاح X-OpenWA-Idempotency-Key، ثم الحفظ المشفّر والمعالجة المعزولة. 200 بعد الحفظ دائماً
 * (الفاشل يُعاد بـ whatsapp:replay-webhooks)، و503 لفشل الحفظ العابر وحده فتعيده البوابة (retryCount).
 * البوابة لا تصل إلا من داخل شبكة compose (SSRF_ALLOWED_HOSTS=nuskjo-app)، والتوقيع يبقى إلزامياً رغم ذلك.
 */
class OpenWaWebhookController extends Controller
{
    public function receive(Request $request, string $channel)
    {
        @set_time_limit((int) config('whatsapp.webhook.time_limit', 55));
        $raw = $request->getContent();
        if (strlen($raw) > (int) config('whatsapp.webhook.max_bytes')) {
            WaLog::warning('webhook.too_large', ['bytes' => strlen($raw)]);

            return response()->json(['ok' => false], 413);
        }

        $ch = preg_match('/^[0-9a-f-]{36}$/i', $channel) ? WaChannel::where('uuid', $channel)->first() : null;
        if ($ch === null) {
            return response()->json(['ok' => false], 404);
        }

        $secret = (string) rescue(fn () => $ch->webhook_secret, '', false);
        $given = trim((string) $request->header('X-OpenWA-Signature', ''));
        if ($secret === '' || $given === '' || !hash_equals('sha256='.hash_hmac('sha256', $raw, $secret), $given)) {
            $count = WebhookIntake::badSignature();
            WaLog::warning('webhook.bad_signature', ['count' => $count, 'channel_id' => $ch->id]);
            AuditLog::sampled(AuditAction::WaWebhookBadSignature, 'owa_sig:'.$request->ip(), 'webhook', null, null, [
                'has_header' => $given !== '', 'bytes' => strlen($raw), 'channel_id' => $ch->id,
            ]);

            return response()->json(['ok' => false], 403);
        }

        $envelope = json_decode($raw, true);
        if (!is_array($envelope) || !is_string($envelope['event'] ?? null)) {
            WaLog::warning('webhook.malformed', ['bytes' => strlen($raw), 'channel_id' => $ch->id]);

            return response()->json(['ok' => true]);
        }
        // حمولة موقّعة بسر هذا الرقم لكنها لجلسة أخرى: لا تُعالج أبداً
        if ((string) ($envelope['sessionId'] ?? '') !== (string) $ch->openwa_session_id) {
            WaLog::warning('webhook.session_mismatch', ['channel_id' => $ch->id, 'event' => $envelope['event']]);

            return response()->json(['ok' => false], 409);
        }

        if (Cache::add('wa:sig:ok:tick', 1, 60)) {
            Cache::forever('wa:sig:last_ok_at', now()->toIso8601String());
        }

        // البوابة تعيد التوصيلة نفسها بمفتاحها عند المهلة: مرة واحدة تكفي
        $idem = mb_substr((string) $request->header('X-OpenWA-Idempotency-Key', $envelope['idempotencyKey'] ?? ''), 0, 200);
        $dedupe = $idem !== '' ? 'wa:owa:idem:'.hash('sha256', $ch->id.'|'.$idem) : null;
        if ($dedupe !== null && Cache::has($dedupe)) {
            return response()->json(['ok' => true, 'duplicate' => true]);
        }

        $event = null;
        if (WebhookIntake::storesAll()) {
            try {
                [$event, $settled] = WebhookIntake::store($raw);
                if ($settled) {
                    return response()->json(['ok' => true]);
                }
            } catch (QueryException|\PDOException $e) {
                WaLog::error('webhook.store_failed', ['exception' => $e]);

                return response()->json(['ok' => false], 503)->header('Retry-After', '30');
            }
        }

        try {
            $result = WebhookIntake::processOpenWa($event, $ch, $envelope);
        } catch (\Throwable $e) {
            WaLog::error('webhook.error', ['exception' => $e, 'channel_id' => $ch->id]);
            if ($event !== null) {
                rescue(fn () => WebhookIntake::failWhole($event, $e), report: false);
            } elseif ($e instanceof QueryException || $e instanceof \PDOException) {
                return response()->json(['ok' => false], 503);
            }
            $result = null;
        }

        if ($event === null && $result?->failed()) {
            $stored = false;
            if (WebhookIntake::storesFailures()) {
                try {
                    WebhookIntake::storeFailed($raw, $result);
                    $stored = true;
                } catch (QueryException|\PDOException $e) {
                    WaLog::error('webhook.store_failed', ['exception' => $e]);
                }
            }
            if (!$stored && $result->transient) {
                return response()->json(['ok' => false], 503);
            }
        }
        if ($dedupe !== null) {
            Cache::put($dedupe, 1, now()->addDays(2));
        }

        return response()->json(['ok' => true]);
    }
}
