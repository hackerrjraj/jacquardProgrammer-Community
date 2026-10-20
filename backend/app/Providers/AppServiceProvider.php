<?php

namespace App\Providers;

use App\Support\BdPhone;
use App\Support\ProfileLists;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Machine / Skill names, read once per request (Phase 11d-1).
        $this->app->scoped(ProfileLists::class);
        $this->app->terminating(fn () => $this->app->forgetInstance(ProfileLists::class));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Contact form: 5 messages per hour per IP, with a bn/en error message.
        RateLimiter::for('contact', fn (Request $request) => Limit::perHour(5)
            ->by($request->ip())
            ->response(fn (Request $request, array $headers) => response()->json(
                ['message' => __('messages.contact_rate_limited')],
                429,
                $headers,
            )));

        // Login: 5 attempts per minute per mobile number + IP, and 20 per minute per IP over all numbers (Phase 12c).
        $loginLimited = fn (Request $request, array $headers) => response()->json(
            ['message' => __('messages.login_rate_limited')],
            429,
            $headers,
        );
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by(BdPhone::normalize((string) $request->input('phone')).'|'.$request->ip())->response($loginLimited),
            Limit::perMinute(20)->by('ip|'.$request->ip())->response($loginLimited),
        ]);

        // Change password (needs the current password): 5 tries per 10 minutes per account (Phase 12c).
        RateLimiter::for('password', fn (Request $request) => Limit::perMinutes(10, 5)
            ->by((string) ($request->user()?->id ?? $request->ip()))
            ->response(fn (Request $request, array $headers) => response()->json(
                ['message' => __('messages.password_rate_limited')],
                429,
                $headers,
            )));

        // Registration: 10 per hour per IP.
        RateLimiter::for('register', fn (Request $request) => Limit::perHour(10)
            ->by($request->ip())
            ->response(fn (Request $request, array $headers) => response()->json(
                ['message' => __('messages.register_rate_limited')],
                429,
                $headers,
            )));

        // Public programmers directory: 60 requests per minute per IP.
        RateLimiter::for('directory', fn (Request $request) => Limit::perMinute(60)
            ->by($request->ip())
            ->response(fn (Request $request, array $headers) => response()->json(
                ['message' => __('messages.directory_rate_limited')],
                429,
                $headers,
            )));

        // Public gallery: 60 requests per minute per IP (same as the directory, own counter).
        RateLimiter::for('gallery', fn (Request $request) => Limit::perMinute(60)
            ->by($request->ip())
            ->response(fn (Request $request, array $headers) => response()->json(
                ['message' => __('messages.directory_rate_limited')],
                429,
                $headers,
            )));

        // Public notices: 60 requests per minute per IP (same as the directory, own counter).
        RateLimiter::for('notices', fn (Request $request) => Limit::perMinute(60)
            ->by($request->ip())
            ->response(fn (Request $request, array $headers) => response()->json(
                ['message' => __('messages.directory_rate_limited')],
                429,
                $headers,
            )));

        // Contribution payment submission (MASTER_SPEC §38): 20 tries per hour per member (wrong form tries count too).
        RateLimiter::for('contributions', fn (Request $request) => Limit::perHour(20)
            ->by((string) ($request->user()?->id ?? $request->ip()))
            ->response(fn (Request $request, array $headers) => response()->json(
                ['message' => __('messages.contribution_rate_limited')],
                429,
                $headers,
            )));

        // Aid request submission (Phase 8a): 10 tries per hour per member (wrong form tries count too).
        RateLimiter::for('assistance', fn (Request $request) => Limit::perHour(10)
            ->by((string) ($request->user()?->id ?? $request->ip()))
            ->response(fn (Request $request, array $headers) => response()->json(
                ['message' => __('messages.assistance_rate_limited')],
                429,
                $headers,
            )));

        // Chat messages (MASTER_SPEC §35, §38): 30 per minute per member.
        RateLimiter::for('chat', fn (Request $request) => Limit::perMinute(30)
            ->by((string) ($request->user()?->id ?? $request->ip()))
            ->response(fn (Request $request, array $headers) => response()->json(
                ['message' => __('messages.chat_rate_limited')],
                429,
                $headers,
            )));

        // Public Home data (expertise cards, stats): 60 requests per minute per IP (own counter).
        RateLimiter::for('home', fn (Request $request) => Limit::perMinute(60)
            ->by($request->ip())
            ->response(fn (Request $request, array $headers) => response()->json(
                ['message' => __('messages.directory_rate_limited')],
                429,
                $headers,
            )));
    }
}
