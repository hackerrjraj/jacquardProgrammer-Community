<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Basic security headers on every backend response (Phase 12c, MASTER_SPEC §38).
 * The API only sends JSON and private files, so the CSP can be very strict: nothing may load, nothing may frame it.
 * Files (payment screenshots, PDFs) get the same headers minus the CSP so the browser's own image / PDF viewer works.
 */
class SecurityHeaders
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $headers->remove('X-Powered-By');

        if (str_contains((string) $headers->get('Content-Type'), 'json')) {
            $headers->set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
        }
        // HSTS only over HTTPS (production) — never on http://localhost.
        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
