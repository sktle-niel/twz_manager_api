<?php

namespace App\Http\Concerns;

use App\Support\ResetPin;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\RateLimiter;

/*
 * The shared lock on everything the reset PIN opens. Wrong guesses share one
 * counter per owner account wherever the PIN is asked for — guessing at the
 * change-PIN form is the same attack as guessing at the reset form.
 */
trait ChecksResetPin
{
    /**
     * Try the PIN behind the shared throttle. Null means it matched;
     * anything else is the response to send back as-is.
     */
    private function resetPinGate(string $ownerId, string $pin, string $field, string $wrong): ?JsonResponse
    {
        $throttleKey = 'reset-pin:'.$ownerId;

        if (RateLimiter::tooManyAttempts($throttleKey, (int) config('twz.reset_pin_attempts'))) {
            $minutes = (int) ceil(RateLimiter::availableIn($throttleKey) / 60);

            return response()->json(
                ['message' => "Too many wrong PINs. Try again in {$minutes} minutes."],
                429,
            );
        }

        if (! ResetPin::matches($pin)) {
            RateLimiter::hit($throttleKey, (int) config('twz.reset_pin_lockout_seconds'));

            return $this->fieldErrors([$field => $wrong]);
        }

        RateLimiter::clear($throttleKey);

        return null;
    }
}
