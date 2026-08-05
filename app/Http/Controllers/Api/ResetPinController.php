<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ResetPin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\RateLimiter;

/*
 * The owner's view of the reset PIN. The PIN itself is never sent back — only
 * a hash is stored, so there is nothing to send. What the owner can learn is
 * whether it is still the value the system shipped with, which is worth
 * saying out loud, because a documented default is not a secret.
 */
class ResetPinController extends Controller
{
    /** GET /api/settings/reset-pin */
    public function show(): JsonResponse
    {
        return response()->json([
            'isDefault' => ResetPin::isDefault(),
            'length' => ResetPin::LENGTH,
            'changedAt' => ResetPin::changedAt()?->toIso8601String(),
        ]);
    }

    /** PUT /api/settings/reset-pin */
    public function update(Request $request): Response|JsonResponse
    {
        $fields = $request->validate([
            'currentPin' => ['required', 'string'],
            'newPin' => ['required', 'string', 'digits:'.ResetPin::LENGTH],
        ]);

        /* Changing the PIN needs the old one, and guessing at it here would be
           the same attack as guessing at it on the reset form — so it shares
           the same counter. */
        $throttleKey = 'reset-pin:'.$request->user()->id;
        $allowed = (int) config('twz.reset_pin_attempts');

        if (RateLimiter::tooManyAttempts($throttleKey, $allowed)) {
            $minutes = (int) ceil(RateLimiter::availableIn($throttleKey) / 60);

            return response()->json(
                ['message' => "Too many wrong PINs. Try again in {$minutes} minutes."],
                429,
            );
        }

        if (! ResetPin::matches($fields['currentPin'])) {
            RateLimiter::hit($throttleKey, (int) config('twz.reset_pin_lockout_seconds'));

            return response()->json([
                'message' => 'Check the highlighted fields.',
                'fields' => ['currentPin' => 'That is not the current PIN.'],
            ], 422);
        }

        RateLimiter::clear($throttleKey);
        ResetPin::change($fields['newPin']);

        return response()->noContent();
    }
}
