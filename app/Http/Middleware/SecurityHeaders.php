<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/*
 * The response headers a browser needs in order to defend the app for us.
 *
 * One origin serves the API and the PWA, so one policy covers both. Every
 * value here was chosen against what this app actually does, not copied from
 * a checklist — the two that matter most are the ones a careless version
 * would break:
 *
 *   - `camera=(self)`, never `camera=()`. SlipCamera.tsx calls getUserMedia
 *     to photograph deposit slips. Denying the camera would silently remove
 *     the way slips get filed, which is the app's core workflow.
 *   - `style-src 'unsafe-inline'`. The charts and eight components set inline
 *     styles at runtime; without it the dashboard renders unstyled. Inline
 *     SCRIPT stays forbidden, which is the half that stops an injection.
 */
class SecurityHeaders
{
    /** Loaded from our own origin only — the build bundles its own fonts */
    private const CSP = "default-src 'self'; "
        ."script-src 'self'; "
        ."style-src 'self' 'unsafe-inline'; "
        /* blob: is the camera capture and every photo preview before upload;
           data: is the placeholder avatar */
        ."img-src 'self' data: blob:; "
        ."font-src 'self' data:; "
        ."connect-src 'self'; "
        ."media-src 'self' blob:; "
        ."worker-src 'self'; "
        ."object-src 'none'; "
        ."base-uri 'self'; "
        ."form-action 'self'; "
        ."frame-ancestors 'none'";

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('Referrer-Policy', 'same-origin');
        $headers->set('X-Frame-Options', 'DENY');
        /* The camera is needed; nothing else is */
        $headers->set('Permissions-Policy', 'camera=(self), microphone=(), geolocation=(), payment=()');

        /* FileController sends a far stricter policy with its photos
           (`default-src 'none'; sandbox`). Never widen that back out. */
        if (! $headers->has('Content-Security-Policy')) {
            $headers->set('Content-Security-Policy', self::CSP);
        }

        /*
         * A year, and deliberately no includeSubDomains and no preload:
         * other subdomains of the domain may not have TLS, and preload is
         * effectively irreversible for months. Only sent over HTTPS, so
         * local development is untouched.
         */
        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        return $response;
    }
}
