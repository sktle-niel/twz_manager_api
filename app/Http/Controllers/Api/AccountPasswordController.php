<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

/*
 * An account changing its own password — the ordinary door, open to both
 * roles. The current password is the proof of identity here: a session alone
 * is not enough, so a device left signed in cannot silently re-key the
 * account it is holding.
 *
 * This is also what makes the owner's seeded password truly temporary. The
 * seeder only sets it while no owner exists; once this endpoint has run, the
 * database owns the credential and no reseed brings the .env value back.
 */
class AccountPasswordController extends Controller
{
    /** PUT /api/account/password */
    public function update(Request $request): Response|JsonResponse
    {
        $fields = $request->validate([
            'current' => ['required', 'string'],
            'next' => ['required', 'string', PasswordRule::min(8)],
        ]);

        $user = $request->user();

        if (! Hash::check($fields['current'], $user->password)) {
            return $this->fieldErrors(['current' => 'That is not your current password.']);
        }

        $user->forceFill([
            'password' => $fields['next'],
            /* Every remembered device except this session is signed out —
               whoever just proved they hold the password decides who stays */
            'remember_token' => Str::random(60),
        ])->save();

        return response()->noContent();
    }
}
