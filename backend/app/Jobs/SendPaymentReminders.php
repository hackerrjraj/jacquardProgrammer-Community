<?php

namespace App\Jobs;

use App\Support\PaymentReminder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Daily 2:00 PM (Asia/Dhaka) payment-due reminder. One try only; the day check inside makes a second run harmless. */
class SendPaymentReminders implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function handle(): void
    {
        PaymentReminder::sendToday();
    }
}
