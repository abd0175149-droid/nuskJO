<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// مطابقة دورية بين الدفترين (المبسّط مقابل القيود) + إشعار الإدارة عند أي انحراف
Schedule::command('accounting:reconcile')
    ->dailyAt('01:00')
    ->withoutOverlapping();
