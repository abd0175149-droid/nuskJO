<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Jobs\ProcessWhatsAppReply;
use App\Models\AuditLog;
use App\Models\WaMessage;
use App\Models\WaWebhookEvent;
use App\Services\Ops\FailuresCenter;
use App\Services\WhatsApp\MetaErrorCatalog;
use App\Services\WhatsApp\WaLog;
use App\Services\WhatsApp\WhatsAppChannel;
use App\Support\AuditActor;
use App\Support\FeatureFlags;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * مركز «الإخفاقات والطابور» (E24، العلم failures_center؛ مطفأ = 404): قراءة من المصادر القائمة (FailuresCenter)،
 * وثلاثة أفعال محروسة ومدقَّقة: إعادة إرسال رسالة فشلت فشلاً عابراً (العلم failures_redrive)، وqueue:retry لأصناف
 * config('ops.retryable_jobs') وحدها، وإعادة إشعار ويبهوك محفوظ عبر whatsapp:replay-webhooks (E20).
 * كل دالة بـ whatsapp.settings، وصف الرسالة عبر الربط المقيّد بالنطاق (404 خارجه).
 */
class WhatsAppFailuresController extends Controller
{
    public function index(): Response
    {
        $this->guard();

        return Inertia::render('WhatsApp/Failures', [
            'title' => 'الإخفاقات والطابور',
            'initial' => FailuresCenter::payload(auth()->user()),
        ]);
    }

    public function data(): JsonResponse
    {
        $this->guard();

        return response()->json(FailuresCenter::payload(auth()->user()))->header('Cache-Control', 'no-store');
    }

    /**
     * الإعادة المحروسة بالترتيب، وكل رفض بنص عربي: صادر failed بمفتاح محرَّر (لا unknown ولا ما عليه علامة المنظّف:
     * ربما وصل)، والنافذة مفتوحة، والفئة عابرة (MetaErrorCatalog::redrivable)، والمستلم كما كان، ثم قفل 30 ثانية،
     * ثم WhatsAppChannel::redrive على الصف نفسه بالمفتاح نفسه، ثم صف تدقيق صارم بالنتيجة.
     */
    public function redrive(WaMessage $message): JsonResponse
    {
        $this->guard();
        abort_unless(FeatureFlags::on('failures_redrive'), 404);

        $payload = (array) ($message->payload ?? []);
        if ($message->direction !== 'out') {
            return $this->refuse('NOT_REDRIVABLE', 'لا يمكن إعادة هذه الرسالة.');
        }
        if (in_array($message->status, [WaMessage::STATUS_UNKNOWN, 'unconfirmed'], true) || !empty($payload['_reaped'])) {
            return $this->refuse('MAYBE_DELIVERED', 'قد تكون وصلت — تحقق من المحادثة قبل أي إعادة.');
        }
        if ($message->status !== WaMessage::STATUS_FAILED || empty($payload['_released_key'])) {
            return $this->refuse('NOT_REDRIVABLE', 'لا يمكن إعادة هذه الرسالة من هنا — أعد إرسالها من المحادثة.');
        }
        $conversation = $message->conversation;
        if (!$conversation->isWindowOpen()) {
            return $this->refuse('WINDOW_CLOSED', 'النافذة مغلقة — لا يمكن الإرسال حتى يكتب العميل.');
        }
        $metaCode = $message->meta_code !== null ? (int) $message->meta_code : null;
        if (!MetaErrorCatalog::redrivable($metaCode, $message->error_code, $message->error_category)) {
            $category = $message->error_category ?: ($metaCode !== null ? MetaErrorCatalog::classify($metaCode)['category'] : null);

            return in_array($category, ['auth', 'integrity'], true)
                ? $this->refuse('ACCOUNT_ERROR', 'خطأ حساب — يُعالج من WhatsApp Manager لا بالإعادة.')
                : $this->refuse('NOT_TRANSIENT', 'سبب الفشل عند العميل أو في الرسالة نفسها — الإعادة لا تصلحه.');
        }
        // المستلم لا يتغيّر: صف أُرسل إلى رقم غير رقم المحادثة الآن (دمج أو تغيّر رقم) لا يُعاد من هنا
        $to = $payload['to'] ?? null;
        if ($to !== null && (string) $to !== (string) $conversation->wa_phone) {
            return $this->refuse('RECIPIENT_CHANGED', 'تغيّر رقم العميل منذ الإرسال — أرسل من المحادثة.', 409);
        }

        $lock = Cache::lock('wa:redrive:'.$message->id, 30);
        if (!$lock->get()) {
            return $this->refuse('REDRIVE_BUSY', 'إعادة أخرى لهذه الرسالة جارية — انتظر لحظة.', 409);
        }
        try {
            $result = WhatsAppChannel::redrive($message, (int) auth()->id());
        } finally {
            $lock->release();
        }

        $outcome = $result['ok'] ? 'ok' : (string) ($result['code'] ?? 'failed');
        AuditLog::log(AuditAction::WaRedrive, 'wa_message', $message->id, null, [
            'conversation_id' => $message->conversation_id, 'old_code' => $metaCode ?? $message->error_code, 'result' => $outcome,
        ]);
        WaLog::info('wa.redrive', ['conv_id' => $message->conversation_id, 'message_id' => $message->id, 'status' => $outcome]);

        $status = match (true) {
            $result['ok'] => 200,
            !empty($result['unknown']) => 202,
            !empty($result['conflict']) => 409,
            default => 422,
        };
        $fresh = WaMessage::with('conversation')->find($message->id);

        return response()->json([
            'ok' => (bool) $result['ok'], 'code' => $result['code'] ?? null, 'error' => $result['error'] ?? null,
            'message' => $fresh ? FailuresCenter::presentMessage($fresh) : null,
        ], $status);
    }

    /** queue:retry لأصناف معروفة بأنها آمنة الإعادة؛ رد البوت لا يُعاد أبداً (رمز الدمج انتهى، والرد قديم) */
    public function retryJob(string $uuid): JsonResponse
    {
        $this->guard();
        $row = DB::table('failed_jobs')->where('uuid', $uuid)->first(['id', 'uuid', 'queue', 'payload']);
        abort_if($row === null, 404);
        $class = (string) (json_decode((string) $row->payload, true)['displayName'] ?? '');

        if ($class === ProcessWhatsAppReply::class) {
            return $this->refuse('REPLY_JOB', 'ردود البوت لا تُعاد من هنا (قد يخرج رد قديم) — افتح المحادثة.');
        }
        if (!in_array($class, (array) config('ops.retryable_jobs', []), true)) {
            return $this->refuse('NOT_RETRYABLE', 'هذه المهمة لا تُعاد من هنا.');
        }

        DB::transaction(function () use ($row, $class) {
            AuditLog::log(AuditAction::OpsJobRetry, 'failed_job', (int) $row->id, class_basename($class), ['queue' => $row->queue]);
            Artisan::call('queue:retry', ['id' => [$row->uuid]]);
        });

        return response()->json(['ok' => true]);
    }

    /**
     * إعادة إشعار ويبهوك محفوظ بأمر E20 نفسه، فيكتب تدقيقه WaWebhookReplay منسوباً إلى المدير. المتوقف (dead) أو من
     * استنفد محاولاته يحتاج تأكيداً ثانياً (--force). بوابة الحداثة (E07) تمنع الرد الآلي على وارد قديم.
     */
    public function replayWebhook(Request $request, int $id): JsonResponse
    {
        $this->guard();
        $event = WaWebhookEvent::find($id);
        abort_if($event === null, 404);
        if (!in_array($event->status, [WaWebhookEvent::FAILED, WaWebhookEvent::DEAD], true) || $event->getRawOriginal('raw_body') === null) {
            return $this->refuse('NOT_REPLAYABLE', 'لا يُعاد إلا إشعار فاشل أو متوقف ما زال جسمه محفوظاً.');
        }
        $force = $event->status === WaWebhookEvent::DEAD || $event->attempts >= (int) config('whatsapp.webhook.max_attempts', 3);
        if ($force && !$request->boolean('confirm')) {
            return $this->refuse('CONFIRM_REQUIRED', 'الإشعار متوقف بعد محاولاته — أكّد الإعادة بعد إصلاح السبب.');
        }

        $exit = AuditActor::as('user', null, fn () => Artisan::call('whatsapp:replay-webhooks', ['--id' => $event->id] + ($force ? ['--force' => true] : [])),
            (int) auth()->id());
        $event->refresh();

        return response()->json(['ok' => $exit === 0 && $event->status === WaWebhookEvent::PROCESSED, 'status' => $event->status, 'attempts' => (int) $event->attempts],
            $exit === 0 ? 200 : 422);
    }

    private function refuse(string $code, string $message, int $status = 422): JsonResponse
    {
        return response()->json(['ok' => false, 'code' => $code, 'error' => $message], $status);
    }

    /** العلم أولاً (مطفأ = 404 كأن الصفحة غير موجودة)، ثم الصلاحية في الخادم لا بإخفاء الرابط وحده */
    private function guard(): void
    {
        abort_unless(FeatureFlags::on('failures_center'), 404);
        abort_unless(auth()->user()->can('whatsapp.settings'), 403);
    }
}
