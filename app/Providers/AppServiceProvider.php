<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        \App\Models\Agent::observe(\App\Observers\AgentObserver::class);
        \App\Models\Client::observe(\App\Observers\ClientObserver::class);
        \App\Models\Service::observe(\App\Observers\ServiceObserver::class);
        \App\Models\ExpenseCategory::observe(\App\Observers\ExpenseCategoryObserver::class);

        \App\Models\Account::observe(\App\Observers\AccountObserver::class);

        // HR Observers
        \App\Models\Employee::observe(\App\Observers\EmployeeObserver::class);
        \App\Models\Attendance::observe(\App\Observers\AttendanceObserver::class);

        $this->guardDestructiveDatabaseCommands();
    }

    /**
     * لا هجرة على الإنتاج بلا نسخة قبلها، ولا أوامر مسح شاملة على الإنتاج (E01).
     * الحدث MigrationsStarted لا يُطلق إلا عند وجود هجرات معلّقة، فلا تتكرر النسخ مع كل إقلاع.
     */
    private function guardDestructiveDatabaseCommands(): void
    {
        \Illuminate\Support\Facades\DB::prohibitDestructiveCommands($this->app->isProduction());

        \Illuminate\Support\Facades\Event::listen(\Illuminate\Database\Events\MigrationsStarted::class, function () {
            static $done = false;

            $db = (string) config('database.connections.sqlite.database');
            if ($done
                || ! $this->app->isProduction()
                || ! config('backup.pre_migrate')
                || config('database.default') !== 'sqlite'
                || $db === '' || $db === ':memory:' || ! is_file($db)) {
                return;
            }

            // تثبيت جديد (لم تُسجَّل أي هجرة بعد): لا بيانات تحميها النسخة
            try {
                if (\Illuminate\Support\Facades\DB::table('migrations')->count() === 0) {
                    return;
                }
            } catch (\Throwable) {
                // لا نعرف: نأخذ النسخة احتياطاً
            }
            $done = true;

            if (env('ALLOW_MIGRATE_WITHOUT_BACKUP')) {
                \Illuminate\Support\Facades\Log::warning('migrate without pre-migrate backup (ALLOW_MIGRATE_WITHOUT_BACKUP)');

                return;
            }

            // أول هجرة بعد استعادة: القاعدة أصغر من آخر نسخة في spool عن قصد، فلا يمنعها حارس الانكماش
            $args = ['--reason' => 'pre-migrate'];
            try {
                if (\App\Models\Setting::get(\App\Console\Commands\BackupSafeMode::REVIEW_KEY)) {
                    $args['--allow-shrink'] = true;
                }
            } catch (\Throwable) {
                // جدول الإعدادات غير موجود بعد
            }

            if (\Illuminate\Support\Facades\Artisan::call('backup:run', $args) !== 0) {
                throw new \RuntimeException('رفض الهجرة: تعذّر أخذ نسخة قبلها');
            }
        });
    }
}
