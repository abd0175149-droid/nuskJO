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
        $this->definePermissionGate();
        $this->defineLoginRateLimit();
        $this->bindVisibleConversations();
    }

    /**
     * سياج المحادثات (E04): كل مسار فيه {conversation} يحلّ المحادثة من نطاق المستخدم وحده،
     * و404 خارجه (لا 403، حتى لا يكشف الرد وجود المعرّف). الويبهوك والطابور بلا {conversation}.
     */
    private function bindVisibleConversations(): void
    {
        \Illuminate\Support\Facades\Route::bind('conversation', function ($value) {
            $user = auth()->user();
            if (!$user || !ctype_digit((string) $value)) {
                abort(404);
            }

            $conversation = \App\Models\WaConversation::query()->visibleTo($user)->whereKey((int) $value)->first();
            if (!$conversation) {
                \Illuminate\Support\Facades\Log::notice('wa.fence.denied', ['user' => $user->id, 'conversation' => (int) $value]);
                abort(404);
            }

            return $conversation;
        });
    }

    /**
     * حد محاولات الدخول (E03): 5 في الدقيقة لكل بريد وعنوان، و30 لكل عنوان (المكتب خلف عنوان واحد).
     * القفل يُسجَّل في التدقيق ببريد مقنّع فقط.
     */
    private function defineLoginRateLimit(): void
    {
        \Illuminate\Support\Facades\RateLimiter::for('login', function (\Illuminate\Http\Request $request) {
            $email = \Illuminate\Support\Str::lower((string) $request->input('email'));
            $locked = function () use ($request, $email) {
                \App\Models\AuditLog::log('auth.lockout', 'User', 0, self::maskEmail($email), ['ip' => $request->ip()]);

                return back()->withErrors(['email' => 'محاولات دخول كثيرة. انتظر دقيقة ثم أعد المحاولة.'])->onlyInput('email');
            };

            return [
                \Illuminate\Cache\RateLimiting\Limit::perMinute(5)->by($email.'|'.$request->ip())->response($locked),
                \Illuminate\Cache\RateLimiting\Limit::perMinute(30)->by('ip:'.$request->ip())->response($locked),
            ];
        });
    }

    /** a***@domain.com */
    private static function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 1).'***'.($domain !== '' ? '@'.$domain : '');
    }

    /**
     * مسار واحد لكل فحوص الصلاحية (E03): المعطّل مرفوض دائماً، والمدير مسموح دائماً،
     * وغيرهما بصلاحيات دوره. القدرة «admin» للمسارات الخاصة بالمدير وحده.
     */
    private function definePermissionGate(): void
    {
        \Illuminate\Support\Facades\Gate::before(function ($user, string $ability) {
            if (!$user instanceof \App\Models\User) {
                return null;
            }
            if (!$user->is_active) {
                return false;
            }
            if ($user->isAdmin()) {
                return true;
            }

            return in_array($ability, $user->permissionSlugs(), true) ? true : null;
        });

        \Illuminate\Support\Facades\Gate::define('admin', fn (\App\Models\User $user) => $user->isAdmin());
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
