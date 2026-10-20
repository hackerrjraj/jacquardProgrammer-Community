<?php

use App\Jobs\SendPaymentReminders;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Payment-due reminder: every day at 2:00 PM Bangladesh time (needs `php artisan schedule:work` + a queue worker).
Schedule::job(new SendPaymentReminders)->dailyAt('14:00')->timezone('Asia/Dhaka')->name('payment-reminders');
