<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\WaAdminNumber;
use App\Services\NotificationService;
use App\Support\FeatureFlags;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * تقرير الحسابات الخاملة أسبوعياً (E27): حسابات نشطة بلا نشاط منذ 60 يوماً، وأرقام إدارة نشطة لم تُستعمل منذ 90،
 * وعدد أصحاب الصلاحيات الحساسة بلا تحقق بخطوتين. إشعار واحد لكل مدير نشط (العلم idle_report)، بلا أي تعطيل آلي:
 * القرار للمدير. والعلم مطفأ: يطبع التقرير ولا يرسل.
 */
class UsersIdleReport extends Command
{
    protected $signature = 'users:idle-report';

    protected $description = 'تقرير أسبوعي للمدراء بالحسابات وأرقام الإدارة الخاملة (تنبيه فقط)';

    /** أسماء تُذكر في الإشعار، والباقي عدد */
    private const NAMES = 10;

    public function handle(): int
    {
        $userBefore = now()->subDays((int) config('security.idle.user_days', 60));
        $numberBefore = now()->subDays((int) config('security.idle.admin_number_days', 90));

        $users = User::where('is_active', true)
            ->where(fn ($q) => $q->where('last_seen_at', '<', $userBefore)
                ->orWhere(fn ($w) => $w->whereNull('last_seen_at')->where('created_at', '<', $userBefore)))
            ->orderBy('name')->pluck('name');
        $numbers = WaAdminNumber::where('is_active', true)
            ->where(fn ($q) => $q->where('last_used_at', '<', $numberBefore)
                ->orWhere(fn ($w) => $w->whereNull('last_used_at')->where('created_at', '<', $numberBefore)))
            ->orderBy('name')->pluck('name');
        $withoutFactor = User::with('role.permissions:id,slug')->where('is_active', true)->whereNull('two_factor_confirmed_at')->get()
            ->filter(fn (User $u) => $u->requiresMfa())->count();

        $lines = [];
        if ($users->isNotEmpty()) {
            $lines[] = 'حسابات بلا نشاط منذ '.config('security.idle.user_days', 60).' يوماً ('.$users->count().'): '.$this->names($users);
        }
        if ($numbers->isNotEmpty()) {
            $lines[] = 'أرقام إدارة لم تُستعمل منذ '.config('security.idle.admin_number_days', 90).' يوماً ('.$numbers->count().'): '.$this->names($numbers);
        }
        if ($withoutFactor > 0) {
            $lines[] = "أصحاب صلاحيات حساسة بلا تحقق بخطوتين: {$withoutFactor}";
        }

        if ($lines === []) {
            $this->info('لا حسابات خاملة ولا أرقام إدارة خاملة.');

            return self::SUCCESS;
        }
        foreach ($lines as $line) {
            $this->line($line);
        }

        if (!FeatureFlags::on('idle_report')) {
            $this->warn('العلم idle_report مطفأ: لم يُرسل إشعار.');

            return self::SUCCESS;
        }

        NotificationService::notifyAdmins('🕸️ حسابات خاملة هذا الأسبوع', implode("\n", $lines)."\nراجعها وعطّل ما لم يعد يُستعمل (لا تعطيل آلي).", [
            'type' => 'security', 'icon' => '🕸️', 'action_url' => '/users?filter=idle',
        ]);
        $this->info('أُرسل الإشعار للمدراء.');

        return self::SUCCESS;
    }

    private function names(Collection $names): string
    {
        $shown = $names->take(self::NAMES)->implode('، ');

        return $names->count() > self::NAMES ? $shown.' وغيرهم '.($names->count() - self::NAMES) : $shown;
    }
}
