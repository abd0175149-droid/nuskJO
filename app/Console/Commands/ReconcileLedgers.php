<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Models\User;
use App\Services\LedgerReconciliationService;
use App\Services\NotificationService;
use Illuminate\Console\Command;

/**
 * مطابقة دورية بين الدفترين + إشعار الإدارة عند أي انحراف.
 * يُشغّل عبر الجدولة (routes/console.php) أو يدوياً: php artisan accounting:reconcile
 */
class ReconcileLedgers extends Command
{
    protected $signature = 'accounting:reconcile {--no-notify : فحص فقط دون إرسال إشعار}';

    protected $description = 'مطابقة الدفتر المبسّط مع دفتر القيود وإشعار الإدارة عند أي انحراف';

    public function handle(): int
    {
        // مزامنة تلقائية لرصيد العملاء المبسّط من دفتر القيود (تأخّر حميد — لا تنبيه)
        $healed = LedgerReconciliationService::syncClientBalances();
        if (count($healed)) {
            $this->info('🔄 تمت مزامنة ' . count($healed) . ' رصيد عميل من دفتر القيود:');
            foreach ($healed as $h) {
                $this->line("   {$h['code']} - {$h['name']}: {$h['from']} → {$h['to']}");
            }
        }

        $result = LedgerReconciliationService::reconcile();

        $this->info("الانحرافات الخطيرة المكتشفة: {$result['count']}");

        if (!$result['has_divergence']) {
            // مسح التوقيع السابق حتى يُرسَل إشعار جديد إن عاد الانحراف لاحقاً
            Setting::where('key', 'reconcile_signature')->delete();
            $this->info('✅ لا يوجد انحراف بين الدفترين.');
            return self::SUCCESS;
        }

        foreach ($result['issues'] as $i) {
            $extra = isset($i['diff']) ? " (فرق: {$i['diff']})" : '';
            $this->warn("- [{$i['label']}] {$i['ref']}{$extra}");
        }

        if ($this->option('no-notify')) {
            return self::SUCCESS;
        }

        // توقيع الانحرافات لمنع تكرار نفس الإشعار كل تشغيل
        $signature = md5(
            collect($result['issues'])
                ->map(fn ($i) => $i['category'] . '|' . ($i['id'] ?? '') . '|' . ($i['ref'] ?? ''))
                ->sort()->implode(';')
        );

        $last = Setting::where('key', 'reconcile_signature')->value('value');

        if ($signature === $last) {
            $this->info('نفس الانحراف السابق — لم يُرسل إشعار مكرر.');
            return self::SUCCESS;
        }

        $this->sendNotification($result);
        Setting::updateOrCreate(['key' => 'reconcile_signature'], ['value' => $signature]);
        $this->info('📨 تم إرسال إشعار للإدارة.');

        return self::SUCCESS;
    }

    private function sendNotification(array $result): void
    {
        $labels = [
            'missing_journal_entry'   => 'مستندات معتمدة بلا قيد',
            'client_balance_mismatch' => 'اختلاف أرصدة عملاء',
            'account_balance_drift'   => 'انحراف أرصدة حسابات',
            'trial_balance'           => 'اختلال ميزان المراجعة',
        ];

        $summary = collect($result['by_category'])
            ->map(fn ($cnt, $cat) => ($labels[$cat] ?? $cat) . ": {$cnt}")
            ->implode('، ');

        $body = "تم اكتشاف {$result['count']} انحراف بين الدفتر المبسّط ودفتر القيود. ({$summary})";

        $adminIds = User::whereHas('role', fn ($q) => $q->where('slug', 'admin'))
            ->pluck('id')->toArray();

        foreach (array_unique($adminIds) as $uid) {
            try {
                NotificationService::send($uid, '⚠️ انحراف بين الدفاتر المحاسبية', $body, [
                    'type'       => 'error',
                    'icon'       => '⚖️',
                    'action_url' => '/accounting/trial-balance',
                ]);
            } catch (\Throwable $e) {
                \Log::error('فشل إرسال إشعار المطابقة: ' . $e->getMessage());
            }
        }
    }
}
