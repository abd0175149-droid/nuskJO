<?php

namespace App\Console\Commands;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\WaConversation;
use App\Services\Retention\ConversationEraser;
use App\Services\WhatsApp\PhoneNormalizer;
use App\Support\Retention;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * بعد أي استعادة من نسخة (E01): يعيد تطبيق كل حذف نُفّذ، من سجل المحو الخارجي (خارج القاعدة، فلا تمحوه
 * الاستعادة). يطابق بصمة الرقم أو المعرّف مع محادثات القاعدة المستعادة، ويحذف بلا نداءات لميتا.
 */
class WhatsAppErasureReapply extends Command
{
    protected $signature = 'whatsapp:erasure-reapply {--since= : من تاريخ (افتراضياً: كل السجل)} {--dry-run}';

    protected $description = 'إعادة تطبيق طلبات الحذف المنفّذة بعد استعادة نسخة';

    public function handle(ConversationEraser $eraser): int
    {
        $since = $this->option('since') ? Carbon::parse((string) $this->option('since')) : null;
        $entries = collect(Retention::erasureLedger())->filter(fn ($e) => !$since || Carbon::parse($e['done_at'] ?? '1970-01-01')->gte($since));
        $subjects = $entries->pluck('subject_hmac')->filter()->flip();
        $userSubjects = $entries->pluck('wa_user_hmac')->filter()->flip();
        if ($subjects->isEmpty() && $userSubjects->isEmpty()) {
            $this->info('لا حذف مسجّل لإعادة تطبيقه.');

            return self::SUCCESS;
        }

        $matched = 0;
        WaConversation::whereNotIn('status', ['erased', 'pruned'])->where(fn ($q) => $q->whereNotNull('phone')->orWhereNotNull('wa_user_id'))
            ->orderBy('id')->chunkById(500, function ($convs) use (&$matched, $subjects, $userSubjects, $eraser) {
                foreach ($convs as $conv) {
                    $hit = ($conv->phone && $subjects->has(Retention::subjectHmac(PhoneNormalizer::canonical($conv->phone))))
                        || ($conv->wa_user_id && ($userSubjects->has(Retention::userIdHmac($conv->wa_user_id)) || $subjects->has(Retention::userIdHmac($conv->wa_user_id))));
                    if (!$hit) {
                        continue;
                    }
                    $matched++;
                    if ($this->option('dry-run')) {
                        continue;
                    }
                    $counts = $eraser->execute($eraser->plan($conv, 'reapply'));
                    AuditLog::log(AuditAction::WaDataErase, 'wa_conversation', $conv->id, null, ['counts' => $counts, 'mode' => 'reapply']);
                }
            });

        $this->info(($this->option('dry-run') ? 'ستُعاد' : 'أُعيد تطبيق')." الحذف على {$matched} محادثة.");

        return self::SUCCESS;
    }
}
