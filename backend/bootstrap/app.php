<?php

use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocaleFromHeader;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Private channels (Phase 9a): POST /api/broadcasting/auth with the logged-in session cookie.
    ->withBroadcasting(__DIR__.'/../routes/channels.php', ['prefix' => 'api', 'middleware' => ['api', 'auth:sanctum']])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->api(prepend: [SetLocaleFromHeader::class]);
        $middleware->append(SecurityHeaders::class);
        // No login page on the backend (the site is the Next.js frontend): a guest gets 401 JSON, never a redirect to a
        // missing "login" route (that was a 500 for browser requests to private files — Phase 12c).
        $middleware->redirectGuestsTo(fn () => null);
        $middleware->alias([
            'active' => EnsureAccountIsActive::class,
            'role' => EnsureUserHasRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        // Throttles without their own message (e.g. throttle:60,1): a friendly bn/en text instead of "Too Many Attempts." (Phase 12c).
        $exceptions->render(fn (ThrottleRequestsException $e, Request $request) => $request->is('api/*') || $request->expectsJson()
            ? response()->json(['message' => __('messages.too_many_requests')], 429, $e->getHeaders())
            : null);
        // "Please log in" in bn/en (Laravel sends the English text untranslated).
        $exceptions->render(fn (AuthenticationException $e, Request $request) => $request->is('api/*') || $request->expectsJson()
            ? response()->json(['message' => __('Unauthenticated.')], 401)
            : null);
    })->create();
