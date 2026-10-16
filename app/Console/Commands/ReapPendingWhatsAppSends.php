<?php

namespace App\Console\Commands;

use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\Ops\OpsAlerts;
use App\Services\WhatsApp\ReplyState;
use App\Services\WhatsApp\WaLog;
use App\Support\FeatureFlags;
use Illuminate\Console\Command;

/**
 * منظّف دفتر الإرسال (E19.8، كل 10 دقائق مع العلم send_ledger): صف queued أقدم من 15 دقيقة انقطع إرساله قبل الحسم
 * (قُتل العامل أو انهارت الحاوية) وربما خرج الطلب، فيصير «غير مؤكد» (unknown) لا «فشل»، والمفتاح يبقى محجوزاً.
 * والمحادثة «تدخّل» بالسبب send_unknown مع إشعار مقيّد. لا Http إطلاقاً، ولا يعيد الإرسال أبداً: ذاك قرار موظف.
 */
class ReapPendingWhatsAppSends extends Command
{
    protected $signature = 'whatsapp:reap-pending {--dry-run : يطبع الأعداد والمعرّفات بلا كتابة} {--minutes=15 : عمر الصف المعلّق}';

    protected $description = 'يعلّم الرسائل الصادرة المعلّقة «غير مؤكدة» وينبّه، بلا إعادة إرسال';

    /** صفوف «غير مؤكدة» جديدة في التشغيل الواحد فوقها تنبيه انفجار (علامة مشكلة شبكة مع ميتا) */
    private const BURST = 5;

    public function handle(OpsAlerts $alerts): int
    {
        if (!FeatureFlags::on('send_ledger')) {
            $this->line('العلم send_ledger مطفأ: لا شيء.');

            return self::SUCCESS;
        }

        $cutoff = now()->subMinutes(max(1, (int) $this->option('minutes')));
        $dry = (bool) $this->option('dry-run');
        $reaped = [];
        $marked = [];
        $convs = [];

        WaMessage::where('direction', 'out')->where('status', WaMessage::STATUS_QUEUED)->where('created_at', '<', $cutoff)
            ->select(['id', 'conversation_id', 'payload'])->chunkById(200, function ($rows) use ($dry, &$reaped, &$convs) {
                foreach ($rows as $row) {
                    if (!$dry) {
                        $payload = (array) ($row->payload ?? []);
                        $payload['_reaped'] = now()->toIso8601String();
                        $moved = WaMessage::whereKey($row->id)->where('status', WaMessage::STATUS_QUEUED)->update([
                            'status' => WaMessage::STATUS_UNKNOWN, 'error_code' => 'OUTCOME_UNKNOWN', 'error_category' => 'transient',
                            'error_message' => 'انقطع الإرسال قبل أن نعرف رد ميتا — قد تكون الرسالة وصلت.',
                            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                        ]);
                        if ($moved === 0) {
                            continue;
                        }
                    }
                    $reaped[] = $row->id;
                    $convs[$row->conversation_id] = true;
                }
            });

        WaMessage::where('direction', 'out')->where('status', WaMessage::STATUS_UNKNOWN)->where('created_at', '<', $cutoff)
            ->select(['id', 'conversation_id', 'payload'])->chunkById(200, function ($rows) use ($dry, &$marked, &$convs) {
                foreach ($rows as $row) {
                    $payload = (array) ($row->payload ?? []);
                    if (isset($payload['_reaped'])) {
                        continue;
                    }
                    if (!$dry) {
                        $payload['_reaped'] = now()->toIso8601String();
                        WaMessage::whereKey($row->id)->where('status', WaMessage::STATUS_UNKNOWN)->update(['payload' => json_encode($payload, JSON_UNESCAPED_UNICODE)]);
                    }
                    $marked[] = $row->id;
                    $convs[$row->conversation_id] = true;
                }
            });

        if (!$dry) {
            foreach (array_keys($convs) as $convId) {
                if ($conv = WaConversation::find($convId)) {
                    ReplyState::markUnserved($conv, 'send_unknown');
                }
            }
            if ($reaped || $marked) {
                WaLog::warning('wa.send.reaped', ['count' => count($reaped) + count($marked)]);
            }
            if (count($reaped) > self::BURST) {
                $alerts->notifyAdmins('wa:reap:burst', '⚠️ رسائل واتساب لم يُتأكد وصولها',
                    count($reaped).' رسالة انقطع إرسالها قبل رد ميتا خلال الدقائق الأخيرة — قد تكون مشكلة شبكة بين الخادم وميتا.', '/whatsapp/inbox');
            }
        }

        $this->line(($dry ? '[تجربة] ' : '').'غير مؤكدة الآن: '.count($reaped).' · عُلّمت: '.count($marked).' · محادثات: '.count($convs));
        if ($dry && ($reaped || $marked)) {
            $this->line('الصفوف: '.implode(', ', [...$reaped, ...$marked]).' · المحادثات: '.implode(', ', array_keys($convs)));
        }

        return self::SUCCESS;
    }
}
