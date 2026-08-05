<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
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

        /*
         * Asking for a reset link is cheap for the caller and expensive for
         * us: it sends mail to an address the caller names. The broker
         * already refuses a second token for the same account within a
         * minute; this stops one place from walking a list of identifiers to
         * see whose inbox lights up. A branch shares one IP, so the ceiling
         * is loose enough that a genuine forgotten password is never blocked.
         */
        RateLimiter::for('password-resets', function (Request $request) {
            return Limit::perHour(6)
                ->by($request->ip())
                ->response(fn () => response()->json(
                    ['message' => 'Too many reset requests. Try again later, or ask the owner.'],
                    429,
                ));
        });

        /*
         * The reset link must open a page, and every page belongs to the
         * frontend — this backend serves no HTML. Laravel's default builds a
         * URL to a named web route that does not exist here, so we point the
         * notification at the PWA and let it post the token back.
         */
        ResetPassword::createUrlUsing(fn (User $user, string $token) => sprintf(
            '%s/reset-password?token=%s&email=%s',
            config('app.frontend_url'),
            $token,
            urlencode($user->getEmailForPasswordReset()),
        ));
    }
}
