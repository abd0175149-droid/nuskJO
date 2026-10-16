<?php

namespace App\Services\Ops;

use App\Models\User;
use App\Services\NotificationService;
use App\Support\Ops\RunbookMap;
use App\Support\SecretRedactor;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Log;

/**
 * تنبيهات التشغيل للمدراء: مرة في الساعة لكل مفتاح، والدفع للجهاز مباشرة لا عبر الطابور
 * (التنبيه «العامل متوقف» لا يصل إن انتظر العامل نفسه). لا رقم عميل ولا نص رسالة.
 */
final class OpsAlerts
{
    public const URL = '/whatsapp/settings#ops';

    private const HEALTH = [
        'queue_stale' => ['⛔ العامل الخلفي متوقف', 'لا تصل الإشعارات ولا تعمل المهام الخلفية. راجع صحة النظام.'],
        'reply_queue_stale' => ['⛔ عامل ردود البوت متوقف', 'البوت لا يرد على العملاء. راجع صحة النظام.'],
        'schedule_stale' => ['⛔ المجدول متوقف', 'لا تذكيرات نافذة 24 ساعة ولا نسخ احتياطي مجدول. راجع صحة النظام.'],
        'backlog' => ['⏳ مهام متأخرة في الطابور', 'مهام تنتظر أكثر من 5 دقائق ولم يلتقطها عامل.'],
        // db: لا تنبيه من الداخل (الإشعارات في القاعدة نفسها)؛ المراقب الخارجي يتولاه
    ];

    /**
     * يرسل إن لم يُرسل المفتاح نفسه خلال ساعة؛ يعيد هل أُرسل. بالعلم alert_runbook_refs ينتهي النص بكتيّب المفتاح
     * في docs/RUNBOOK.md (config/runbooks.php، E25)، والكبح بالمفتاح كما هو.
     */
    public function notifyAdmins(string $key, string $title, string $body, string $url = self::URL): bool
    {
        if (!OpsHealth::store()->add('ops:alert:'.$key, 1, 3600)) {
            return false;
        }
        $body .= RunbookMap::suffix(RunbookMap::for($key));

        try {
            $admins = User::whereHas('role', fn ($q) => $q->where('slug', 'admin'))->where('is_active', true)->pluck('id');
            foreach ($admins as $id) {
                NotificationService::send((int) $id, $title, $body, [
                    'type' => 'error', 'icon' => '🩺', 'action_url' => $url, 'sync_push' => true,
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('ops.alert_failed', ['class' => $e::class]);
        }

        return true;
    }

    /** @param list<string> $codes */
    public function healthDegraded(array $codes): void
    {
        foreach ($codes as $code) {
            if (isset(self::HEALTH[$code])) {
                $this->notifyAdmins('health:'.$code, ...self::HEALTH[$code]);
            }
        }
    }

    /**
     * Queue::failing: سطر منقّى (اسم الوظيفة ومعرّف المحادثة وصنف الاستثناء ورسالته بلا أسرار ولا أرقام)،
     * وإشعار لكل صنف وظيفة مرة في الساعة.
     */
    public function jobFailed(JobFailed $event): void
    {
        $name = class_basename($event->job->resolveName());
        $command = (string) data_get($event->job->payload(), 'data.command', '');
        // من النص المسلسل مباشرة، بلا unserialize
        $conversationId = preg_match('/"conversationId";i:(\d+);/', $command, $m) ? (int) $m[1] : null;

        Log::error('job_failed', [
            'job' => $name,
            'conversation_id' => $conversationId,
            'exception' => $event->exception::class,
            'message' => self::sanitize($event->exception->getMessage()),
        ]);

        $this->notifyAdmins('jobfailed:'.$name, '⚠️ فشلت مهمة خلفية', "فشلت {$name} — راجع صحة النظام.");
    }

    /** الأسرار (SecretRedactor) ثم أي رقم طويل (هاتف) */
    public static function sanitize(string $message): string
    {
        $clean = preg_replace('/\+?\d[\d\s\-]{6,}\d/u', '[رقم]', SecretRedactor::clean($message)) ?? '';

        return mb_substr($clean, 0, 300);
    }
}
