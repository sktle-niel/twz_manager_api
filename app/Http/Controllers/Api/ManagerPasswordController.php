<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ResetPin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

/*
 * How a forgotten password gets fixed now that nothing is mailed anywhere:
 * the manager asks the owner, and the owner sets a new one here. No old
 * password is needed — the whole point is that nobody has it.
 *
 * Two locks, because this endpoint hands over an account. Being signed in as
 * the owner is the first. The PIN is the second, so an unattended laptop
 * still signed in cannot be used to take a branch.
 */
class ManagerPasswordController extends Controller
{
    /** PUT /api/managers/{manager}/password */
    public function update(Request $request, string $manager): Response|JsonResponse
    {
        $fields = $request->validate([
            'pin' => ['required', 'string'],
            'password' => ['required', 'string', PasswordRule::min(8)],
        ]);

        $owner = $request->user();
        $throttleKey = 'reset-pin:'.$owner->id;
        $allowed = (int) config('twz.reset_pin_attempts');

        if (RateLimiter::tooManyAttempts($throttleKey, $allowed)) {
            $minutes = (int) ceil(RateLimiter::availableIn($throttleKey) / 60);

            return response()->json(
                ['message' => "Too many wrong PINs. Try again in {$minutes} minutes."],
                429,
            );
        }

        if (! ResetPin::matches($fields['pin'])) {
            RateLimiter::hit($throttleKey, (int) config('twz.reset_pin_lockout_seconds'));

            return response()->json([
                'message' => 'Check the highlighted fields.',
                'fields' => ['pin' => 'That is not the PIN.'],
            ], 422);
        }

        RateLimiter::clear($throttleKey);

        $target = User::query()->find($manager);

        if ($target === null) {
            return response()->json(['message' => 'That account is no longer there.'], 404);
        }

        /* The owner's own password is deliberately not resettable from inside
           the app: whoever is holding this screen is already signed in as the
           owner, so it would be a lock that opens itself. If the owner is the
           one locked out, `php artisan twz:set-password` over SSH is the way
           back — see the README. */
        if ($target->isOwner()) {
            return response()->json(
                ['message' => 'Use Account to change your own password.'],
                422,
            );
        }

        $target->forceFill([
            'password' => $fields['password'],
            // Any device still holding a "remember me" cookie is signed out
            'remember_token' => Str::random(60),
        ])->save();

        return response()->noContent();
    }
}
