<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Picks bn or en from the Accept-Language header (default bn) so API
 * messages, including validation errors, come back in the user's language.
 */
class SetLocaleFromHeader
{
    public const SUPPORTED = ['bn', 'en'];

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->hasHeader('Accept-Language')
            ? $request->getPreferredLanguage(self::SUPPORTED)
            : null;

        app()->setLocale(in_array($locale, self::SUPPORTED, true) ? $locale : 'bn');

        return $next($request);
    }
}
