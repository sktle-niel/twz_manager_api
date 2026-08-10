<?php

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ChecksResetPin;
use App\Http\Controllers\Controller;
use App\Support\ResetPin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/*
 * The owner's view of the reset PIN. The PIN itself is never sent back — only
 * a hash is stored, so there is nothing to send. What the owner can learn is
 * whether it is still the value the system shipped with, which is worth
 * saying out loud, because a documented default is not a secret.
 */
class ResetPinController extends Controller
{
    use ChecksResetPin;

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
        $denied = $this->resetPinGate(
            (string) $request->user()->id,
            $fields['currentPin'],
            'currentPin',
            'That is not the current PIN.',
        );
        if ($denied !== null) {
            return $denied;
        }

        ResetPin::change($fields['newPin']);

        return response()->noContent();
    }
}
