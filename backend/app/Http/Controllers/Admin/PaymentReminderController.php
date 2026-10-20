<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Setting;
use App\Support\PaymentReminder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Payment Reminder admin page (admin + super_admin by route middleware): message, on/off, who would receive it. */
class PaymentReminderController extends Controller
{
    /** GET /api/admin/payment-reminder */
    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->payload()]);
    }

    /** PUT /api/admin/payment-reminder */
    public function update(Request $request): JsonResponse
    {
        $max = PaymentReminder::MAX;
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'message_bn' => ['nullable', 'string', 'max:'.$max],
            'message_en' => ['nullable', 'string', 'max:'.$max],
        ], [
            'message_bn.max' => __('messages.terms_too_long', ['max' => $max]),
            'message_en.max' => __('messages.terms_too_long', ['max' => $max]),
        ]);

        $old = PaymentReminder::stored();
        $new = ['enabled' => (bool) $data['enabled']];
        foreach (PaymentReminder::DEFAULTS as $field => $default) {
            $value = trim((string) ($data[$field] ?? ''));
            $new[$field] = $value !== '' ? $value : $default; // empty = default text
        }
        $changed = array_values(array_filter(array_keys($new), fn ($f) => $old[$f] !== $new[$f]));

        if ($changed) {
            DB::transaction(function () use ($request, $new, $changed) {
                Setting::put(Setting::PAYMENT_REMINDER, json_encode($new + ['updated_at' => now()->toIso8601String()], JSON_UNESCAPED_UNICODE));
                AuditLog::record($request, 'payment_reminder.updated', null, ['fields' => $changed]);
            });
        }

        return response()->json(['message' => __('messages.payment_reminder_saved'), 'data' => $this->payload()]);
    }

    private function payload(): array
    {
        $recipients = PaymentReminder::recipients();

        return PaymentReminder::stored() + [
            'recipient_count' => $recipients->count(),
            'total_due' => (int) $recipients->sum('total_due'),
            'recipients' => $recipients->take(200)->map(fn (array $r) => [
                'id' => $r['user']->id,
                'name' => $r['user']->name,
                'phone' => $r['user']->phone,
                'total_due' => $r['total_due'],
                'months_due' => $r['months_due'],
                'months_pending' => $r['months_pending'],
            ])->values(),
        ];
    }
}
