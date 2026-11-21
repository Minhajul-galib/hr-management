<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline response headers the app had none of. Each closes a specific,
 * cheap attack rather than being generic hardening for its own sake:
 *
 * - X-Content-Type-Options: stops the browser from re-sniffing a response's
 *   type away from what the server declared (relevant to the Clifto Talk
 *   attachment/logo upload fixes elsewhere in this pass).
 * - X-Frame-Options: this app never needs to be framed by another origin,
 *   so there's no reason to leave clickjacking on the login/settings/admin
 *   pages open.
 * - Referrer-Policy: full URLs (which can carry query-string tokens) stop
 *   leaking to third-party origins via the Referer header on outbound links.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        return $response;
    }
}
