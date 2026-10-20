<?php

namespace App\Http\Controllers;

use App\Support\PaymentReminder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The dashboard popup asks this: should the payment reminder be shown to the signed-in member? */
class PaymentReminderController extends Controller
{
    /** GET /api/payment-reminder/status */
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();
        $due = PaymentReminder::dueOf($user);
        $show = PaymentReminder::enabled() && PaymentReminder::eligible($user)
            && $due['months_due'] + $due['months_pending'] > 0;

        return response()->json(['data' => [
            'show' => $show,
            'message' => $show ? PaymentReminder::message(app()->getLocale()) : null,
            'total_due' => $show ? $due['total_due'] : 0,
            'months_due' => $show ? $due['months_due'] : 0,
            'pay_url' => '/dashboard/payments',
        ]]);
    }
}
