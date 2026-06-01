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
    }
}
