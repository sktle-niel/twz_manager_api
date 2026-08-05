<?php

namespace App\Providers;

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
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        /*
         * The api middleware group throttles through this limiter — the group
         * opts in with throttleApi() in bootstrap/app.php. Keyed by account
         * when signed in so one shop device cannot starve another behind the
         * same NAT; by IP before sign-in. The 429 body keeps the contract's
         * {message} shape — the frontend shows it verbatim.
         */
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(120)
                ->by($request->user()?->id ?? $request->ip())
                ->response(fn () => response()->json(
                    ['message' => 'Too many requests just now. Try again in a moment.'],
                    429,
                ));
        });

    }
}
