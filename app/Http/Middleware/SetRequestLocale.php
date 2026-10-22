<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Uses `Accept-Language` (`en`, `en-NG`, …) to localise `formatted` money strings and
 * messages. Unknown or malformed tags fall back to the app locale.
 */
final class SetRequestLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $header = (string) $request->headers->get('Accept-Language', '');
        $first = trim(explode(',', $header)[0] ?? '');
        $tag = trim(explode(';', $first)[0]);

        if (preg_match('/^[a-zA-Z]{2,3}(-[a-zA-Z]{2})?$/', $tag)) {
            [$lang, $region] = array_pad(explode('-', $tag), 2, null);
            App::setLocale(strtolower($lang).($region ? '-'.strtoupper($region) : ''));
        }

        return $next($request);
    }
}
