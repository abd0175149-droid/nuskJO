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
 * تطبيق حالة تسليم من ويبهوك ميتا على الصف الصادر (E21، العلم delivery_status_v2؛ بدونه يبقى مسار
 * WhatsAppInbox::handleStatus القديم):
 *
 * 1) الصف بـ wamid، وإلا بـ biz_opaque_callback_data ببادئة هذه النسخة (E19)، وإلا فالإيصال يتيم ينتظر في الكاش
 *    (OrphanStatus) حتى يُكتب معرّفه بعد ردّ ميتا.
 * 2) تحديث ذري واحد مشروط WHERE status IS NULL OR status IN (DeliveryStatus::allowedFrom): لا قراءة ثم كتابة،
 *    فالرتبة الأعلى تفوز أياً كان ترتيب الوصول (SQLite يسلسل الكتابات). status_at وقت ميتا لا وقت الوصول.
 * 3) التاريخ payload.statusHistory يُلحق في جملة SQL ثانية مستقلة بلا تكرار ({s, t, c}، 12 عنصراً حداً أقصى)،
 *    حتى لو لم تتقدّم الحالة. فشلها لا يمنع تحديث الحالة. يحتاج SQLite 3.31 أو أحدث ('$[#]').
 * 4) عند التقدّم الفعلي وحده: الحدثان WaMessageStatusChanged وWaMessageFailed، وmarkUnserved للبوت والنظام (E07)،
 *    ورفع شارة send_unknown (E19). المفاعل (E18) لكل failed مطابَقة، ومفتاحه wamid يمنع التكرار.
 * لا شيء هنا يرسل إلى ميتا أو يمس أقفال القناة.
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

    public static function apply(array $st, bool $fromOrphan = false): void
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

        // ميتا توصي بالبناء على code لا على title: MetaError من الجدول الوحيد (E18)، وdetails لا تُكتب في عمود
        $failed = $status === DeliveryStatus::FAILED;
        $code = is_numeric(data_get($st, 'errors.0.code')) ? (int) data_get($st, 'errors.0.code') : null;
        $err = null;
        if ($failed || $code !== null) {
            try {
                $err = MetaError::fromStatusError((array) data_get($st, 'errors.0', []));
            } catch (\Throwable $e) {
                WaLog::error('wa.status.classify_failed', ['type' => class_basename($e)]);
            }
        }

        $row = WaMessage::where('wamid', $wamid)->where('direction', 'out')->first(['id', 'conversation_id', 'source', 'staff_id', 'status', 'wamid']);
        $byOpaque = false;
        if (!$row) {
            $row = self::byOpaque($st, $wamid);
            $byOpaque = $row !== null;
        }
        if (!$row) {
            // يتيم: إيصال سبق حفظ المعرّف (failed لا تحمل biz_opaque في أمثلة المرجع)
            if (!$fromOrphan) {
                OrphanStatus::remember($wamid, $st);
            }

            return;
        }

        $affected = self::advance($row, $wamid, $status, $metaAt, $err, $code, $byOpaque, $st);
        if ($byOpaque && $affected === 0) {
            // حالة أدنى متأخرة بـ biz_opaque: الحالة لا تتراجع، والمعرّف يُكمَل
            WaMessage::whereKey($row->id)->whereNull('wamid')->update(['wamid' => $wamid]);
        }

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
    private static function advance(WaMessage $row, string $wamid, string $status, Carbon $metaAt, ?MetaError $err, ?int $code, bool $byOpaque, array $st): int
    {
        $cols = ['status' => $status, 'status_at' => $metaAt];
        if ($byOpaque) {
            $cols['wamid'] = $wamid;
        }

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
            if ($code !== null) {
                $cols['error_message'] = MetaErrorCatalog::v2() ? $err->staffMessage
                    : MetaErrorCatalog::message($code, (string) data_get($st, 'errors.0.title', ''));
            }
            if ($err->details !== null) {
                $json = DB::getPdo()->quote((string) json_encode($err->forPayload(), JSON_UNESCAPED_UNICODE));
                $cols['payload'] = DB::raw("json_set(coalesce(payload, '{}'), '$._meta_error', json({$json}))");
            }
        } elseif ($code !== null) {
            $cols['meta_code'] = $code;
        }

        return WaMessage::whereKey($row->id)
            ->where(fn ($w) => $w->whereNull('status')->orWhereIn('status', DeliveryStatus::allowedFrom($status)))
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

    /** ما بعد التحديث: الفشل (سجل ومفاعل وإبلاغ)، ورد آلي لم يصل، و«غير مؤكد» ثبت، وهوية المستلم */
    private static function afterStatus(WaMessage $row, array $st, string $wamid, string $status, bool $advanced, ?MetaError $err, ?int $code): void
    {
        $v2 = MetaErrorCatalog::v2();
        if ($status === DeliveryStatus::FAILED && $err) {
            WaLog::warning('wa.delivery_failed', ['conv_id' => $row->conversation_id, 'message_id' => $row->id, 'wamid' => $wamid,
                'meta_code' => $err->metaCode, 'error_code' => $err->code, 'category' => $err->category]);
            if (!$v2 && $code !== null) {
                MetaErrorCatalog::react($code);
            }
            // الويبهوك المكرر لا يعيد التنبيه ولا العدّ (المفتاح يحمل wamid)، وfailed المتأخرة تُعدّ ولو لم تتقدّم
            MetaErrorReactor::react($err, ['conv_id' => $row->conversation_id, 'message_id' => $row->id, 'wamid' => $wamid, 'source' => 'webhook']);
            if ($advanced) {
                self::notifySenderDirect($row->id, (int) $row->conversation_id, $row->source, $row->staff_id, $err);
            }
        } elseif ($code !== null && !$v2) {
            MetaErrorCatalog::react($code);
        }

        // رد آلي لم يصل: المحادثة تحتاج موظفاً (E07). استدعاء صريح لا مستمع: جزء من بوابة الرد
        if ($advanced && $status === DeliveryStatus::FAILED && in_array($row->source, ['bot', 'system'], true)
            && ReplyState::available() && ($conv = WaConversation::find($row->conversation_id))) {
            ReplyState::markUnserved($conv, 'send_failed');
        }
        if ($advanced && $status !== DeliveryStatus::FAILED && in_array($row->status, self::PENDING, true)) {
            self::clearSendUnknown((int) $row->conversation_id);
        }

        // حالة فيها الرقم والمعرّف معاً تملأ معرّف محادثة رقمها معروف (E11)
        if (filled(data_get($st, 'recipient_user_id')) && ($c = WaConversation::find($row->conversation_id))) {
            ContactIdentity::fillFromStatus($st, $c);
        }
    }

    /**
     * إبلاغ مرسل رسالة الموظف التي لم تصل لسبب عند العميل (E18 T4)، مرة واحدة بلا نص الرسالة. موضع القرار الوحيد:
     * مع wa_event_bus يتولاه المستمع NotifyDeliveryFailure، وهنا لا شيء.
     */
    public static function notifySenderDirect(int $messageId, int $conversationId, ?string $source, ?int $staffId, MetaError $err): void
    {
        if (!MetaErrorCatalog::v2() || FeatureFlags::on('wa_event_bus') || $source !== 'staff' || !$staffId || $err->scope !== 'recipient') {
            return;
        }
        if (!Cache::add("wa:delivery-failed:notify:{$messageId}", 1, now()->addDays(2))) {
            return;
        }
        $name = optional(WaConversation::find($conversationId))->noticeLabel() ?? 'العميل';
        NotificationService::send($staffId, '⚠️ لم تصل رسالتك', "لم تصل رسالتك إلى {$name}: {$err->staffMessage}",
            ['type' => 'whatsapp', 'icon' => '⚠️', 'action_url' => "/whatsapp/inbox?conv={$conversationId}"]);
    }

    /**
     * صف صادر بلا معرّف بعد (queued أو unknown) تطابقه حالة ميتا برقمه في biz_opaque_callback_data ببادئة هذه النسخة وحدها (E19).
     * يُقبل إن كان معرّفه فارغاً أو مساوياً. والبادئة الغريبة تُسجَّل وتُترك.
     */
    public static function byOpaque(array $st, string $wamid): ?WaMessage
    {
        $opaque = (string) data_get($st, 'biz_opaque_callback_data', '');
        if ($opaque === '' || !preg_match('/^([a-z0-9]+):m:(\d+)$/', $opaque, $m)) {
            return null;
        }
        if ($m[1] !== config('whatsapp.send.opaque_prefix', 'nj')) {
            WaLog::info('wa.status.foreign_opaque', ['wamid' => $wamid]);

            return null;
        }

        return WaMessage::where('id', (int) $m[2])->where('direction', 'out')
            ->where(fn ($q) => $q->whereNull('wamid')->orWhere('wamid', $wamid))->first();
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
        $value = WebhookFields::name($status);
        if (Cache::add('wa:status:unknown:'.$value, 1, now()->addHour())) {
            WaLog::info('wa.status.unknown_value', ['status' => $value]);
        }
    }

    /** للاختبارات: إعادة فحص الأعمدة بعد هجرة أو تراجع */
    public static function forgetSchema(): void
    {
        self::$ready = null;
    }
}
