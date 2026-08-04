<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/*
 * The CSRF layer for a cookie-authenticated API.
 *
 * The session cookie is SameSite=Lax, which already keeps browsers from
 * attaching it to cross-site POSTs — this middleware is the second lock on
 * the same door. Any browser writing to the API sends an Origin header; if
 * one is present it must be an origin we serve. Requests without an Origin
 * (curl, server-to-server, some same-origin form posts) pass — they carry
 * no ambient browser credentials to abuse.
 *
 * Laravel's token CSRF middleware is deliberately not used here: it would
 * demand an X-XSRF-TOKEN dance from a frontend whose contract (docs/API.md)
 * promises plain requests.
 */
class VerifyOriginOnUnsafeRequests
{
    private const UNSAFE = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array($request->method(), self::UNSAFE, true)) {
            return $next($request);
        }

        $origin = $request->headers->get('Origin');
        if ($origin === null || in_array($origin, $this->allowedOrigins($request), true)) {
            return $next($request);
        }

        return response()->json(['message' => 'That did not go through.'], 403);
    }

    /** @return list<string> */
    private function allowedOrigins(Request $request): array
    {
        // The app's own origin, plus the dev frontend origins CORS already trusts
        return array_values(array_unique(array_filter([
            $request->getSchemeAndHttpHost(),
            ...config('cors.allowed_origins', []),
        ])));
    }
}
