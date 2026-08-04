<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** The owner area of the API — managers get the contract's 403 body */
class EnsureOwner
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->isOwner()) {
            return response()->json(['message' => 'You do not have access to that.'], 403);
        }

        return $next($request);
    }
}
