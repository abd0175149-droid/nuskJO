<?php

namespace App\Console\Commands;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\WaAdminNumber;
use App\Models\WaConversation;
use App\Services\Retention\ConversationEraser;
use App\Support\AuditActor;
use App\Support\FeatureFlags;
use App\Support\Retention;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * الاحتفاظ على مستوى المحادثة (E13.6): المدة من آخر تواصل. يُستثنى ما يرتبط بفاتورة، وعميل له رصيد أو رحلة
 * قادمة، و«تدخّل»، ومحادثات الإدارة (إلا بمدتها الخاصة). معطّل افتراضياً: المدة 0 أو العلم retention_purge
 * مطفأ أو لا نسخة ليلية حديثة = لا شيء. --dry-run يعدّ فقط ولا يكتب.
 */
class WhatsAppPrune extends Command
{
    protected $signature = 'whatsapp:prune {--days= : المدة بالأيام (افتراضياً WA_RETENTION_DAYS)} {--dry-run} {--max-batches=} {--json}';

    protected $description = 'حذف المحادثات القديمة بحسب مدة الاحتفاظ (معطّل افتراضياً)';

    public function handle(ConversationEraser $eraser): int
    {
        $days = (int) ($this->option('days') ?? config('retention.conversations_days'));
        if ($days <= 0) {
            $this->line('معطّل: لا مدة احتفاظ (WA_RETENTION_DAYS=0).');

            return self::SUCCESS;
        }
        $dry = (bool) $this->option('dry-run');
        if (!$dry && !FeatureFlags::on('retention_purge')) {
            $this->line('معطّل: العلم retention_purge مطفأ.');

            return self::SUCCESS;
        }
        if (!$dry && !Retention::backupFresh()) {
            $this->line('متوقف: لا نسخة احتياطية ناجحة خلال 26 ساعة.');

            return self::SUCCESS;
        }

        $budget = max(1, (int) ($this->option('max-batches') ?? config('retention.max_batches', 200))) * max(1, (int) config('retention.batch_size', 500));
        $adminDays = (int) config('retention.admin_chats_days', 0);
        $adminPhones = WaAdminNumber::pluck('phone')->filter()->flip();
        $skipped = ['invoiced' => 0, 'balance' => 0, 'attention' => 0, 'admin' => 0];
        $totals = ['conversations' => 0, 'deleted_rows' => 0, 'messages' => 0, 'notes' => 0, 'quotes' => 0];

        foreach ($this->candidates(min($days, $adminDays > 0 ? $adminDays : $days)) as $conv) {
            if ($totals['messages'] >= $budget) {
                break;
            }
            $isAdmin = $conv->is_admin_chat || ($conv->phone && $adminPhones->has($conv->phone));
            $cutoff = now()->subDays($isAdmin ? $adminDays : $days);
            if ($isAdmin && $adminDays <= 0) {
                $skipped['admin']++;

                continue;
            }
            if (($conv->last_message_at ?? $conv->created_at) >= $cutoff || ($conv->last_inbound_at && $conv->last_inbound_at >= $cutoff)) {
                continue;
            }
            if ($conv->needs_attention) {
                $skipped['attention']++;

                continue;
            }
            if ($reason = $this->protectedBy($conv)) {
                $skipped[$reason]++;

                continue;
            }

            $plan = $eraser->plan($conv, 'retention');
            $totals['conversations']++;
            $totals['messages'] += $plan->counts['messages'];
            $totals['notes'] += $plan->counts['notes'];
            $totals['quotes'] += $plan->counts['quotes'];
            if ($dry) {
                continue;
            }
            $eraser->execute($plan);
            // لا طلب تسعير يشير إليها: يُحذف صفها نهائياً، وإلا تبقى شاهداً pruned
            if (!DB::table('quote_requests')->whereIn('conversation_id', $plan->conversationIds)->exists()) {
                $totals['deleted_rows'] += DB::table('wa_conversations')->whereIn('id', $plan->conversationIds)->delete();
            }
        }

        $summary = ['days' => $days, 'dry_run' => $dry] + $totals + ['skipped' => $skipped];
        if (!$dry) {
            Retention::recordShrink(['wa_messages' => $totals['messages'], 'wa_conversations' => $totals['deleted_rows'], 'quote_requests' => $totals['quotes']]);
            AuditActor::as('system', 'artisan:whatsapp:prune', fn () => AuditLog::log(AuditAction::RetentionPruned, 'retention', null, 'conversations', $summary));
        }
        $this->option('json') ? $this->line(json_encode($summary, JSON_UNESCAPED_UNICODE))
            : $this->table(['البند', 'العدد'], collect($totals + ['skipped_'.implode('/', array_keys($skipped)) => implode('/', $skipped)])->map(fn ($v, $k) => [$k, $v])->values()->all());

        return self::SUCCESS;
    }

    /** الأقدم أولاً، بحد أقل المدتين (والتحقق الدقيق لكل محادثة في الحلقة) */
    private function candidates(int $days)
    {
        $cutoff = now()->subDays($days);

        return WaConversation::whereNotIn('status', ['erased', 'pruned', 'merged'])
            ->whereRaw('COALESCE(last_message_at, created_at) < ?', [$cutoff])
            ->orderByRaw('COALESCE(last_message_at, created_at)')->lazyById(200);
    }

    /** محفوظة لأسباب مالية: فاتورة من طلباتها، أو عميل برصيد مفتوح أو رحلة قادمة */
    private function protectedBy(WaConversation $conv): ?string
    {
        $invoiced = DB::table('quote_requests')->where('conversation_id', $conv->id)
            ->where(fn ($q) => $q->whereNotNull('invoice_id')->orWhereExists(fn ($e) => $e->selectRaw('1')->from('invoices')->whereColumn('invoices.quote_request_id', 'quote_requests.id')))
            ->exists();
        if ($invoiced) {
            return 'invoiced';
        }
        if ($conv->client_id) {
            $balance = DB::table('clients')->join('accounts', 'accounts.id', '=', 'clients.account_id')->where('clients.id', $conv->client_id)->value('accounts.balance');
            $upcoming = DB::table('invoices')->where('client_id', $conv->client_id)->whereDate('trip_date', '>', now()->toDateString())->exists();
            if (abs((float) $balance) >= 0.001 || $upcoming) {
                return 'balance';
            }
        }

        return null;
    }
}
