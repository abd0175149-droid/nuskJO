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
        // صفحة الخصوصية تسمّي كل مزوّد ذكاء مفعّل، أساسياً واحتياطياً (E12.6). composer لا Closure في المسار،
        // فلا يتجمّد النص مع route:cache
        \Illuminate\Support\Facades\View::composer('legal.privacy', function ($view) {
            $view->with('aiProviders', rescue(function () {
                $s = \App\Models\WaBotSetting::current();
                $names = [\App\Services\WhatsApp\Llm\LlmFactory::PROVIDERS[$s->provider] ?? 'Google Gemini'];
                if ($s->fallbackReady()) {
                    $names[] = \App\Services\WhatsApp\Llm\LlmFactory::PROVIDERS[$s->fallback_provider] ?? $s->fallback_provider;
                }

                return array_values(array_unique($names));
            }, ['Google Gemini'], false));
        });

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
        \Illuminate\Support\Facades\Event::subscribe(\App\Listeners\AuditAuthEvents::class);
        // التقليم نفسه يترك أثراً (فئة system، فلا يُقلَّم)
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Database\Events\ModelsPruned::class, function ($event) {
            if ($event->count <= 0) {
                return;
            }
            // التدقيق نفسه (E09) بفعله، وبقية الجداول بـ retention.pruned وعدد صفوفها (E13)
            $audit = $event->model === \App\Models\AuditLog::class;
            \App\Support\AuditActor::as('system', 'artisan:model:prune', fn () => \App\Models\AuditLog::safe(
                $audit ? \App\Enums\AuditAction::AuditPruned : \App\Enums\AuditAction::RetentionPruned,
                $audit ? 'audit_log' : 'retention', null, $audit ? null : class_basename($event->model), ['count' => $event->count]));
        });
        $this->bindVisibleConversations();
        $this->watchOperations();

        // لا يتبع أي نداء Http تحويلاً ما لم يطلبه صراحة (المفاتيح في الترويسات قد تُحمل لمضيف آخر)
        if (config('services.outbound.block_redirects')) {
            \Illuminate\Support\Facades\Http::globalOptions(['allow_redirects' => false]);
        }
    }

    /**
     * صحة التشغيل (E08): /up يفشل إن توقفت القاعدة أو المجدول أو أحد العاملين، وكل وظيفة تفشل نهائياً
     * تُسجَّل منقّاة ويُنبَّه المدراء مرة في الساعة لكل صنف.
     */
    private function watchOperations(): void
    {
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Foundation\Events\DiagnosingHealth::class, function () {
            if (config('ops.health_checks')) {
                app(\App\Services\Ops\OpsHealth::class)->assertHealthy();
            }
        });
        \Illuminate\Support\Facades\Queue::failing(fn (\Illuminate\Queue\Events\JobFailed $e) => app(\App\Services\Ops\OpsAlerts::class)->jobFailed($e));
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

        // طلبات التسعير بالنطاق نفسه حين يُفعَّل fence_quotes (وإلا كالربط الضمني)
        // رسالة واتساب (مرفقات E14): عبر محادثتها المرئية للمستخدم وحدها، و404 لا 403 فلا يُكشف وجود المعرّف
        \Illuminate\Support\Facades\Route::bind('message', function ($value) {
            $user = auth()->user();
            if (!$user || !ctype_digit((string) $value)) {
                abort(404);
            }
            $message = \App\Models\WaMessage::whereKey((int) $value)->whereHas('conversation', fn ($q) => $q->visibleTo($user))->first();
            if (!$message) {
                \App\Services\WhatsApp\WaLog::notice('wa.media.fence', ['message_id' => (int) $value]);
                abort(404);
            }

            return $message;
        });

        \Illuminate\Support\Facades\Route::bind('quoteRequest', function ($value) {
            $user = auth()->user();
            if (!$user || !ctype_digit((string) $value)) {
                abort(404);
            }

            return \App\Models\QuoteRequest::query()->visibleTo($user)->whereKey((int) $value)->first() ?? abort(404);
        });
    }

    /**
     * حد محاولات الدخول (E03): 5 في الدقيقة لكل بريد وعنوان، و30 لكل عنوان (المكتب خلف عنوان واحد).
     * القفل حدث Lockout يدقّقه AuditAuthEvents (E09) ببريد مقنّع وبالعيّنات.
     */
    private function defineLoginRateLimit(): void
    {
        \Illuminate\Support\Facades\RateLimiter::for('login', function (\Illuminate\Http\Request $request) {
            $email = \Illuminate\Support\Str::lower((string) $request->input('email'));
            $locked = function () use ($request) {
                event(new \Illuminate\Auth\Events\Lockout($request));

                return back()->withErrors(['email' => 'محاولات دخول كثيرة. انتظر دقيقة ثم أعد المحاولة.'])->onlyInput('email');
            };

            return [
                \Illuminate\Cache\RateLimiting\Limit::perMinute(5)->by($email.'|'.$request->ip())->response($locked),
                \Illuminate\Cache\RateLimiting\Limit::perMinute(30)->by('ip:'.$request->ip())->response($locked),
            ];
        });
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

            // env() عمداً: متغير لأمر واحد على سطر الأوامر (ALLOW_MIGRATE_WITHOUT_BACKUP=1 php artisan migrate)،
            // ويصل حتى مع config:cache لأنه من بيئة العملية لا من .env (ArchitectureSettingsTest يستثنيه وحده)
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
