<?php

namespace App\Http\Controllers;

use App\Http\Requests\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\BdPhone;
use App\Support\RegistrationTerms;
use Illuminate\Support\Arr;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Member auth (MASTER_SPEC §16–§17). Sanctum SPA cookie sessions: the frontend first
 * calls GET /sanctum/csrf-cookie, then sends X-XSRF-TOKEN with every POST.
 */
class AuthController extends Controller
{
    /** POST /api/register — new account waits for admin approval (status = pending). */
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = new User(Arr::except($request->validated(), ['terms_accepted']));
        if (RegistrationTerms::isActive()) {
            $user->terms_accepted_at = now();
            $user->terms_version = RegistrationTerms::version();
        }
        $user->save();

        return response()->json([
            'message' => __('messages.registered'),
            'data' => new UserResource($user->refresh()),
        ], 201);
    }

    /** POST /api/login — mobile + password. Only approved accounts get a session. */
    public function login(Request $request): JsonResponse
    {
        $request->merge(['phone' => BdPhone::normalize($request->input('phone'))]);
        $credentials = $request->validate([
            'phone' => ['required', 'string', 'max:20'],
            'password' => ['required', 'string', 'max:100'],
        ]);

        $user = User::where('phone', $credentials['phone'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages(['phone' => __('messages.login_failed')]);
        }

        // Status is only revealed after a correct password.
        if (! $user->isApproved()) {
            return response()->json([
                'message' => $user->statusMessage(),
                'status' => $user->status,
                'rejection_reason' => $user->status === User::STATUS_REJECTED ? $user->rejection_reason : null,
            ], 403);
        }

        Auth::guard('web')->login($user);
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        return response()->json([
            'message' => $user->must_change_password
                ? __('messages.password_change_required')
                : __('messages.logged_in'),
            'data' => new UserResource($user),
        ]);
    }

    /** POST /api/logout */
    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['message' => __('messages.logged_out')]);
    }

    /** GET /api/me — the logged-in member's own account. */
    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    /** POST /api/change-password — also clears must_change_password after an admin reset. */
    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string', 'max:100'],
            'password' => ['required', 'string', 'min:8', 'max:100', 'confirmed', 'different:current_password'],
        ]);

        $user = $request->user();

        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages(['current_password' => __('messages.current_password_wrong')]);
        }

        $user->password = $data['password'];
        $user->must_change_password = false;
        $user->save();

        // Sessions on other devices still hold the old password hash and are logged out by Sanctum.
        return response()->json([
            'message' => __('messages.password_changed'),
            'data' => new UserResource($user),
        ]);
    }
}
