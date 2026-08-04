<?php

use App\Http\Middleware\EnsureOwner;
use App\Http\Middleware\VerifyOriginOnUnsafeRequests;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
         * The api group carries the session: credentials are an httpOnly
         * cookie, not a bearer token (docs/API.md, frontend repo). Origin
         * verification stands in for token CSRF — see the middleware.
         */
        $middleware->api(prepend: [
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            VerifyOriginOnUnsafeRequests::class,
        ]);

        /* An API guest gets the 401 JSON body, never a redirect to a login
           page that does not exist — the frontend's fetch sends Accept: * */
        $middleware->redirectGuestsTo(
            fn (Request $request) => $request->is('api/*') ? null : '/',
        );

        $middleware->alias([
            'owner' => EnsureOwner::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        /*
         * The error body the frontend parses: { message, fields? } with one
         * human-readable message per field — not Laravel's array-of-messages
         * shape. client.ts shows `message` verbatim and puts each `fields`
         * entry beside its form field.
         */
        $exceptions->render(function (ValidationException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $fields = collect($e->errors())
                ->map(fn (array $messages) => $messages[0])
                ->all();

            return response()->json([
                'message' => 'Check the highlighted fields.',
                'fields' => $fields,
            ], 422);
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json(
                ['message' => 'Your session has expired. Sign in again.'],
                401,
            );
        });
    })->create();
