<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Only approved accounts may use logged-in routes. If an account was suspended
 * (or otherwise lost approval) while logged in, the session is ended here.
 *
 * Used as `active:password`, an account whose password was reset by an admin
 * must also change it before doing anything else.
 */
class EnsureAccountIsActive
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, ?string $check = null): Response
    {
        $user = $request->user();

        if ($user && ! $user->isApproved()) {
            $message = $user->statusMessage();

            Auth::guard('web')->logout();
            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            return response()->json(['message' => $message, 'status' => $user->status], 403);
        }

        if ($check === 'password' && $user?->must_change_password) {
            return response()->json([
                'message' => __('messages.password_change_required'),
                'must_change_password' => true,
            ], 403);
        }

        return $next($request);
    }
}
