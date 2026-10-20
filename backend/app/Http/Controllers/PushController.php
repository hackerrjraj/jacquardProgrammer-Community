<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use NotificationChannels\WebPush\PushSubscription;

/**
 * Phone / desktop Web Push (Phase 10c-1, MASTER_SPEC §36): VAPID public key, subscribe / unsubscribe this browser,
 * and the member's on/off switch. Same access as the bell (approved accounts) and never for demo accounts.
 * The push text language follows the website language the member used when subscribing or changing the setting.
 */
class PushController extends Controller
{
    /** GET /api/push/public-key */
    public function publicKey(Request $request): JsonResponse
    {
        $this->ensureAllowed($request);

        return response()->json(['data' => ['public_key' => (string) config('webpush.vapid.public_key')]]);
    }

    /** POST /api/push/subscribe — {endpoint, keys: {p256dh, auth}, content_encoding?} */
    public function subscribe(Request $request): JsonResponse
    {
        $user = $this->ensureAllowed($request);
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'url', 'starts_with:https://', 'max:'.PushSubscription::ENDPOINT_MAX_LENGTH],
            'keys' => ['required', 'array'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'content_encoding' => ['nullable', 'string', 'in:aesgcm,aes128gcm'],
        ]);

        $user->updatePushSubscription(
            $data['endpoint'], $data['keys']['p256dh'], $data['keys']['auth'], $data['content_encoding'] ?? 'aes128gcm'
        );
        $user->push_enabled = true;
        $user->push_locale = $this->locale();
        $user->save();

        return response()->json(['message' => __('messages.push_subscribed'), 'data' => $this->settings($user)]);
    }

    /** POST /api/push/unsubscribe — {endpoint}; the last device removed → switch off. */
    public function unsubscribe(Request $request): JsonResponse
    {
        $user = $this->ensureAllowed($request);
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:'.PushSubscription::ENDPOINT_MAX_LENGTH],
        ]);

        $user->deletePushSubscription($data['endpoint']);
        if (! $user->pushSubscriptions()->exists()) {
            $user->push_enabled = false;
            $user->save();
        }

        return response()->json(['message' => __('messages.push_unsubscribed'), 'data' => $this->settings($user)]);
    }

    /** GET /api/push/settings */
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->settings($this->ensureAllowed($request))]);
    }

    /** PUT /api/push/settings — {push_enabled}; switching on needs a subscribed device. */
    public function update(Request $request): JsonResponse
    {
        $user = $this->ensureAllowed($request);
        $data = $request->validate(['push_enabled' => ['required', 'boolean']]);
        $on = (bool) $data['push_enabled'];

        if ($on && ! $user->pushSubscriptions()->exists()) {
            return response()->json([
                'message' => __('messages.push_no_device'),
                'errors' => ['push_enabled' => [__('messages.push_no_device')]],
            ], 422);
        }

        $user->push_enabled = $on;
        $user->push_locale = $this->locale();
        $user->save();

        return response()->json(['message' => __('messages.push_settings_saved'), 'data' => $this->settings($user)]);
    }

    /** Approved accounts reach here (route middleware); demo accounts are refused too. */
    private function ensureAllowed(Request $request)
    {
        $user = $request->user();
        abort_if(! $user->isApproved() || $user->isDemo(), 403, __('messages.push_not_available'));

        return $user;
    }

    private function locale(): string
    {
        return app()->getLocale() === 'en' ? 'en' : 'bn';
    }

    /** @return array{push_enabled: bool, devices: int} */
    private function settings($user): array
    {
        return [
            'push_enabled' => (bool) $user->push_enabled,
            'devices' => $user->pushSubscriptions()->count(),
        ];
    }
}
