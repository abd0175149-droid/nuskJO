<?php

namespace App\Console\Commands;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\WaWebhookEvent;
use App\Services\WhatsApp\WaLog;
use App\Services\WhatsApp\WebhookIntake;
use App\Support\AuditActor;
use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;

/**
 * إعادة معالجة إشعارات الويبهوك الفاشلة (E20.7، كل دقيقة): فاشل حان موعده، أو pending انقطع طلبه.
 * بميزانية 3 محاولات (الأولى في الطلب) ثم dead مع إشعار مجمّع. بوابة الحداثة (E07) تحوّل الوارد القديم لموظف
 * بدل الرد الآلي، وwamid الفريد يمنع التكرار. لا يُطبع الجسم ولا أي جزء منه، حتى مع --dry-run.
 */
class ReplayWhatsAppWebhooks extends Command
{
    protected $signature = 'whatsapp:replay-webhooks
        {--id= : يعيد صفاً بعينه (بعد إصلاح علة)}
        {--force : مع --id: يتجاوز ميزانية المحاولات}
        {--dry-run : يطبع المعرّف والحالة والمحاولات والحقول فقط}
        {--limit=50 : أقصى عدد في التشغيل}';

    protected $description = 'يعيد معالجة إشعارات واتساب المحفوظة التي فشلت أو انقطعت، بميزانية محاولات';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $id = $this->option('id');

        if ($id !== null) {
            $event = WaWebhookEvent::find((int) $id);
            if (!$event) {
                $this->error('لا صف بهذا المعرّف.');

                return self::FAILURE;
            }
            if (in_array($event->status, [WaWebhookEvent::REJECTED, WaWebhookEvent::MALFORMED, WaWebhookEvent::PROCESSED], true) || $event->getRawOriginal('raw_body') === null) {
                $this->error("الصف #{$event->id} ({$event->status}) لا يُعاد: لا جسم محفوظ أو حُسم.");

                return self::FAILURE;
            }
            if ($event->attempts >= (int) config('whatsapp.webhook.max_attempts', 3) && !$this->option('force')) {
                $this->error("الصف #{$event->id} استنفد محاولاته ({$event->attempts}). أضف --force بعد إصلاح السبب.");

                return self::FAILURE;
            }
            $events = collect([$event]);
            if (!$dry) {
                // المعرّف وحده في التدقيق، بلا جسم
                AuditActor::as('system', 'whatsapp:replay-webhooks', fn () => AuditLog::safe(AuditAction::WaWebhookReplay, 'wa_webhook_event', $event->id,
                    '#'.$event->id, ['status' => $event->status, 'attempts' => $event->attempts, 'force' => (bool) $this->option('force')]));
            }
        } else {
            $events = WaWebhookEvent::replayable()->orderBy('id')->limit(max(1, (int) $this->option('limit')))->get();
        }

        $done = ['processed' => 0, 'failed' => 0, 'dead' => 0];
        foreach ($events as $event) {
            if ($dry) {
                $this->line("#{$event->id} {$event->status} attempts={$event->attempts} ".($event->field_summary ?? '-'));

                continue;
            }
            $status = $this->replay($event);
            $done[$status] = ($done[$status] ?? 0) + 1;
        }

        if (!$dry && $events->isNotEmpty()) {
            WaLog::info('webhook.replay', $done);
        }
        $this->info($dry ? "{$events->count()} صفاً للإعادة." : "عولج {$done['processed']} · فشل {$done['failed']} · تعذّر نهائياً {$done['dead']}");

        return self::SUCCESS;
    }

    private function replay(WaWebhookEvent $event): string
    {
        try {
            $raw = (string) $event->raw_body;
        } catch (DecryptException) {
            // دُوّر APP_KEY بلا APP_PREVIOUS_KEYS: لا يُفك أبداً، فلا معنى لمحاولة أخرى
            $event->forceFill(['status' => WaWebhookEvent::DEAD, 'error' => 'decrypt_failed', 'last_attempt_at' => now(), 'next_attempt_at' => null])->save();
            WebhookIntake::dead();

            return 'dead';
        }

        try {
            WebhookIntake::process($event, $raw);
        } catch (\Throwable $e) {
            // خارج العناصر المعزولة: محاولة فاشلة كاملة
            WaLog::error('webhook.replay_failed', ['event_id' => $event->id, 'exception' => $e]);
            WebhookIntake::failWhole($event, $e);
        }

        return match ($event->fresh()?->status) {
            WaWebhookEvent::PROCESSED, WaWebhookEvent::REJECTED, WaWebhookEvent::MALFORMED => 'processed',
            WaWebhookEvent::DEAD => 'dead',
            default => 'failed',
        };
    }
}
