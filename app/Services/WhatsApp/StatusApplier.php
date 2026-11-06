<?php

namespace App\Services\WhatsApp;

use App\Events\WhatsApp\WaMessageFailed;
use App\Events\WhatsApp\WaMessageStatusChanged;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\NotificationService;
use App\Support\FeatureFlags;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * تطبيق حالة تسليم على الصف الصادر (E21). المصدر منذ 2026-11-06 حدث message.ack وmessage.failed من بوابة OpenWA
 * (applyOpenWa يحوّله إلى الشكل الداخلي {id, status, timestamp}):
 *
 * 1) الصف بـ wamid (على رقم الحدث، أو بلا رقم من قبل الأرقام المتعددة)، وإلا فالإيصال يتيم ينتظر في الكاش
 *    (OrphanStatus) حتى يُكتب معرّفه بعد ردّ البوابة.
 * 2) تحديث ذري واحد مشروط WHERE status IS NULL OR status IN (DeliveryStatus::allowedFrom): لا قراءة ثم كتابة،
 *    فالرتبة الأعلى تفوز أياً كان ترتيب الوصول (SQLite يسلسل الكتابات). status_at وقت الحدث لا وقت الوصول.
 * 3) التاريخ payload.statusHistory يُلحق في جملة SQL ثانية مستقلة بلا تكرار ({s, t, c}، 12 عنصراً حداً أقصى)،
 *    حتى لو لم تتقدّم الحالة. فشلها لا يمنع تحديث الحالة. يحتاج SQLite 3.31 أو أحدث ('$[#]').
 * 4) عند التقدّم الفعلي وحده: الحدثان WaMessageStatusChanged وWaMessageFailed، وmarkUnserved للبوت والنظام (E07)،
 *    ورفع شارة send_unknown (E19). المفاعل (E18) لكل failed مطابَقة، ومفتاحه wamid يمنع التكرار.
 * لا شيء هنا يرسل إلى البوابة أو يمس أقفال القناة.
 */
final class StatusApplier
{
    /** صفوف تنتظر حسمها: تقدّمها الإيجابي يمحو سبب الشك ويضع sent_at (E19) */
    private const PENDING = ['queued', 'unknown', 'unconfirmed'];

    private static ?bool $ready = null;

    /** العلم، وبعد الهجرة 2026_10_14_000021 وحدها */
    public static function enabled(): bool
    {
        if (!FeatureFlags::on('delivery_status_v2')) {
            return false;
        }

        return self::$ready ??= Schema::hasColumns('wa_messages', ['status_at', 'sent_at', 'error_category']);
    }

    /**
     * ack من البوابة: pending لا شيء (الصف queued عندنا)، وsent وdelivered وread تتقدّم، وfailed نهائية برمز DELIVERY_FAILED.
     * الصدى fromMe بالمعرّف نفسه يحسمه EchoHandler، وهنا الحالة وحدها.
     */
    public static function applyOpenWa(\App\Models\WaChannel $channel, array $data, bool $failedEvent = false): void
    {
        $id = $data['messageId'] ?? $data['id'] ?? null;
        $status = strtolower((string) ($data['status'] ?? ''));
        if ($failedEvent) {
            $status = DeliveryStatus::FAILED;
        }
        if (!is_string($id) || $id === '' || !in_array($status, ['sent', 'delivered', 'read', 'played', 'failed'], true)) {
            return;
        }
        $ts = is_numeric($data['timestamp'] ?? null) ? (int) $data['timestamp'] : null;
        if ($ts !== null && $ts > 1_000_000_000_000) {
            $ts = intdiv($ts, 1000);
        }
        self::apply(array_filter(['id' => mb_substr($id, 0, 128), 'status' => $status, 'timestamp' => $ts, 'channel_id' => $channel->id]));
    }

    public static function apply(array $st, bool $fromOrphan = false, ?MetaError $override = null): void
    {
        $wamid = data_get($st, 'id');
        $status = data_get($st, 'status');
        if (!is_scalar($wamid) || !is_scalar($status) || (string) $wamid === '' || (string) $status === '') {
            return;
        }
        $wamid = (string) $wamid;
        $status = strtolower((string) $status);
        if (!DeliveryStatus::isKnown($status)) {
            self::unknownValue($status);

            return;
        }

        // وقت ميتا للحالة، لا يتجاوز «الآن». بلا timestamp: الآن، والتاريخ يحمل t=null
        $ts = is_numeric(data_get($st, 'timestamp')) && (int) data_get($st, 'timestamp') > 0 ? (int) data_get($st, 'timestamp') : null;
        $metaAt = $ts !== null ? Carbon::createFromTimestamp($ts, config('app.timezone')) : now();
        if ($metaAt->greaterThan(now())) {
            $metaAt = now();
        }

        // MetaError من الجدول الوحيد (E18). failed من البوابة بلا رمز: DELIVERY_FAILED (ولو انتظرت يتيمة)
        $failed = $status === DeliveryStatus::FAILED;
        $code = is_numeric(data_get($st, 'errors.0.code')) ? (int) data_get($st, 'errors.0.code') : null;
        $err = $override ?? ($failed && !is_array(data_get($st, 'errors.0')) ? MetaError::local('DELIVERY_FAILED') : null);
        if ($err === null && ($failed || $code !== null)) {
            try {
                $err = MetaError::fromStatusError((array) data_get($st, 'errors.0', []));
            } catch (\Throwable $e) {
                WaLog::error('wa.status.classify_failed', ['type' => class_basename($e)]);
            }
        }

        $channelId = is_numeric(data_get($st, 'channel_id')) ? (int) data_get($st, 'channel_id') : null;
        $row = WaMessage::where('wamid', $wamid)->where('direction', 'out')
            ->when($channelId !== null, fn ($q) => $q->where(fn ($w) => $w->where('channel_id', $channelId)->orWhereNull('channel_id')))
            ->first(['id', 'conversation_id', 'source', 'staff_id', 'status', 'wamid']);
        if (!$row) {
            // يتيم: إيصال سبق حفظ المعرّف بعد ردّ البوابة. بلا العلم لا يُطبَّق أبداً، فلا يُحفظ
            if (!$fromOrphan && self::enabled()) {
                OrphanStatus::remember($wamid, $st);
                // كُتب المعرّف بين البحث والحفظ (وفرّغ الإرسالُ الكاشَ قبلنا): يُطبَّق الآن. التطبيق المكرر بلا أثر
                if (WaMessage::where('wamid', $wamid)->where('direction', 'out')->exists()) {
                    OrphanStatus::drain($wamid);
                }
            }

            return;
        }

        $affected = self::advance($row, $status, $metaAt, $err, $code);

        try {
            self::appendHistory($row->id, $status, $ts, $err?->metaCode ?? $code);
        } catch (\Throwable $e) {
            WaLog::error('wa.status.history_failed', ['message_id' => $row->id, 'type' => class_basename($e)]);
        }

        if ($affected > 0) {
            EventBus::emit(new WaMessageStatusChanged($row->id, (int) $row->conversation_id, $status, $metaAt->getTimestamp(), $err?->metaCode ?? $code));
            if ($failed) {
                EventBus::emit(new WaMessageFailed($row->id, (int) $row->conversation_id, (string) $row->source, $err?->metaCode ?? $code,
                    $err?->category, $err?->scope, 'webhook', $row->staff_id !== null ? (int) $row->staff_id : null));
            }
        }

        self::afterStatus($row, $st, $wamid, $status, $affected > 0, $err, $code);
    }

    /** التحديث الذري المشروط. @return int الصفوف المتأثرة (0: الحالة لم تتقدّم) */
    private static function advance(WaMessage $row, string $status, Carbon $metaAt, ?MetaError $err, ?int $code): int
    {
        // queued وunknown لا تأتيان من البوابة: لا تُكتبان ولو فوق فراغ
        $from = DeliveryStatus::allowedFrom($status);
        if ($from === []) {
            return 0;
        }
        $cols = ['status' => $status, 'status_at' => $metaAt];

        $pending = "'".implode("','", self::PENDING)."'";
        if ($status !== DeliveryStatus::FAILED) {
            // «غير مؤكد» أو فاشل ثبت وصوله: يُمحى السبب وعلامة المنظّف، وsent_at لما كان ينتظر (القيم القديمة في الجملة نفسها)
            $clear = "CASE WHEN status IN ({$pending},'failed') THEN NULL ELSE %s END";
            $cols['error_message'] = DB::raw(sprintf($clear, 'error_message'));
            $cols['error_code'] = DB::raw(sprintf($clear, 'error_code'));
            $cols['error_category'] = DB::raw(sprintf($clear, 'error_category'));
            $cols['sent_at'] = DB::raw("CASE WHEN status IN ({$pending}) AND sent_at IS NULL THEN '".now()->format('Y-m-d H:i:s')."' ELSE sent_at END");
            $cols['payload'] = DB::raw("CASE WHEN status IN ({$pending}) AND payload IS NOT NULL THEN json_remove(payload, '$._reaped') ELSE payload END");
        } elseif ($err !== null) {
            // أعمدة E18: رقم ميتا، ورمزنا الداخلي وفئته، والنص المعروض (الجديد مع meta_error_v2، وإلا نص الجدول القديم)
            if ($err->metaCode !== null) {
                $cols['meta_code'] = $err->metaCode;
            }
            $cols['error_code'] = mb_substr($err->code, 0, 32);
            $cols['error_category'] = $err->category;
            $cols['error_message'] = $err->staffMessage;
            if ($err->details !== null) {
                $json = DB::getPdo()->quote((string) json_encode($err->forPayload(), JSON_UNESCAPED_UNICODE));
                $cols['payload'] = DB::raw("json_set(coalesce(payload, '{}'), '$._meta_error', json({$json}))");
            }
        } elseif ($code !== null) {
            $cols['meta_code'] = $code;
        }

        return WaMessage::whereKey($row->id)
            ->where(fn ($w) => $w->whereNull('status')->orWhereIn('status', $from))
            ->update($cols);
    }

    /** عنصر {s, t, c} يُلحق بلا تكرار (الحالة نفسها بوقت ميتا نفسه)، بسقف whatsapp.status.history_max */
    private static function appendHistory(int $id, string $status, ?int $ts, ?int $metaCode): int
    {
        return DB::update(
            "UPDATE wa_messages SET payload = json_set(coalesce(payload, '{}'), '$.statusHistory',
                    json_insert(coalesce(json_extract(payload, '$.statusHistory'), json('[]')), '$[#]', json(?))),
                updated_at = ?
             WHERE id = ?
               AND coalesce(json_array_length(payload, '$.statusHistory'), 0) < ?
               AND NOT EXISTS (SELECT 1 FROM json_each(wa_messages.payload, '$.statusHistory') j
                               WHERE json_extract(j.value, '$.s') = ? AND json_extract(j.value, '$.t') IS ?)",
            [json_encode(['s' => $status, 't' => $ts, 'c' => $metaCode]), now()->format('Y-m-d H:i:s'), $id,
                max(1, (int) config('whatsapp.status.history_max', 12)), $status, $ts],
        );
    }

    /** ما بعد التحديث: الفشل (سجل وإبلاغ)، ورد آلي لم يصل، و«غير مؤكد» ثبت */
    private static function afterStatus(WaMessage $row, array $st, string $wamid, string $status, bool $advanced, ?MetaError $err, ?int $code): void
    {
        if ($status === DeliveryStatus::FAILED && $err) {
            WaLog::warning('wa.delivery_failed', ['conv_id' => $row->conversation_id, 'message_id' => $row->id, 'wamid' => $wamid,
                'error_code' => $err->code, 'category' => $err->category]);
            if ($advanced) {
                self::notifySenderDirect($row->id, (int) $row->conversation_id, $row->source, $row->staff_id, $err);
            }
        }

        // رد آلي لم يصل: المحادثة تحتاج موظفاً (E07). استدعاء صريح لا مستمع: جزء من بوابة الرد
        if ($advanced && $status === DeliveryStatus::FAILED && in_array($row->source, ['bot', 'system'], true)
            && ReplyState::available() && ($conv = WaConversation::find($row->conversation_id))) {
            ReplyState::markUnserved($conv, 'send_failed');
        }
        if ($advanced && $status !== DeliveryStatus::FAILED && in_array($row->status, self::PENDING, true)) {
            self::clearSendUnknown((int) $row->conversation_id);
        }
    }

    /**
     * إبلاغ مرسل رسالة الموظف التي لم تصل لسبب عند العميل (E18 T4)، مرة واحدة بلا نص الرسالة. موضع القرار الوحيد:
     * مع wa_event_bus يتولاه المستمع NotifyDeliveryFailure، وهنا لا شيء.
     */
    public static function notifySenderDirect(int $messageId, int $conversationId, ?string $source, ?int $staffId, MetaError $err): void
    {
        if (FeatureFlags::on('wa_event_bus') || $source !== 'staff' || !$staffId || $err->scope !== 'recipient') {
            return;
        }
        if (!Cache::add("wa:delivery-failed:notify:{$messageId}", 1, now()->addDays(2))) {
            return;
        }
        $name = optional(WaConversation::find($conversationId))->noticeLabel() ?? 'العميل';
        NotificationService::send($staffId, '⚠️ لم تصل رسالتك', "لم تصل رسالتك إلى {$name}: {$err->staffMessage}",
            ['type' => 'whatsapp', 'icon' => '⚠️', 'action_url' => "/whatsapp/inbox?conv={$conversationId}"]);
    }

    /** لم يبق في المحادثة صف مشكوك فيه: تُرفع شارة send_unknown وحدها، ولا يُمس أي سبب آخر (E19) */
    public static function clearSendUnknown(int $convId): void
    {
        $conv = WaConversation::find($convId);
        if (!$conv || !ReplyState::available() || !$conv->needs_attention || $conv->attention_reason !== 'send_unknown') {
            return;
        }
        $pending = WaMessage::where('conversation_id', $convId)->where('direction', 'out')->whereIn('status', WaMessage::PENDING_STATES)->exists();
        if (!$pending) {
            $conv->forceFill(['needs_attention' => false, 'attention_reason' => null])->save();
        }
    }

    /** قيمة لا نعرفها (deleted مثلاً): لا تُكتب، وسطر واحد في الساعة لكل قيمة */
    private static function unknownValue(string $status): void
    {
        $value = mb_substr(preg_replace('/[^a-z0-9_]/', '', strtolower($status)) ?: 'unknown', 0, 30);
        if (Cache::add('wa:status:unknown:'.$value, 1, now()->addHour())) {
            WaLog::info('wa.status.unknown_value', ['status' => $value]);
        }
    }
}
