<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

/*
 * Forgotten passwords, in two halves: ask for a link, then spend it.
 *
 * The token itself lives in the password_reset_tokens table — hashed, one
 * row per account, replaced whenever a newer link is asked for and deleted
 * the moment it is spent. Laravel's broker owns that table; this controller
 * only decides who is allowed to reach it and what the frontend hears back.
 */
class PasswordResetController extends Controller
{
    /**
     * POST /api/password-resets — resolves 204 whether or not the account
     * exists, so the endpoint cannot be used to enumerate accounts.
     */
    public function store(Request $request): Response
    {
        $credentials = $request->validate(['identifier' => ['required', 'string']]);

        $user = $this->findByIdentifier($credentials['identifier']);

        /* A missing account and a disabled one both fall through silently.
           A disabled manager getting a working reset link would be a way back
           in through a door the owner already closed. */
        if ($user !== null && $user->active) {
            /* The broker refuses a second token for this account inside
               config('auth.passwords.users.throttle') seconds; either way the
               caller hears the same 204. */
            Password::broker()->sendResetLink(['email' => $user->email]);
        }

        return response()->noContent();
    }

    /**
     * POST /api/password-resets/redeem — spends a link.
     *
     * Deliberately not idempotent and deliberately not a GET: the token
     * travels in the body, so it never lands in an access log or a browser
     * history the way a path or query parameter would.
     */
    public function redeem(Request $request): Response|JsonResponse
    {
        $fields = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string', PasswordRule::min(8)],
        ]);

        /* The broker checks that the token matches the account; it has no
           opinion about whether the account is still allowed in. An owner who
           disabled a manager an hour ago would not expect a link mailed
           yesterday to still work. */
        $user = $this->findByIdentifier($fields['email']);
        if ($user === null || ! $user->active) {
            return $this->spentLink();
        }

        $status = Password::broker()->reset($fields, function (User $user, string $password) {
            $user->forceFill([
                'password' => $password,
                /* Any "remember me" cookie still out there stops working —
                   whoever forced the reset should not inherit an old device. */
                'remember_token' => Str::random(60),
            ])->save();

            event(new PasswordReset($user));
        });

        if ($status !== Password::PASSWORD_RESET) {
            return $this->spentLink();
        }

        return response()->noContent();
    }

    /*
     * Expired, already spent, wrong email for the token, or an account
     * disabled since the link was sent — one answer for all of them, so a
     * spent link cannot be told apart from a guessed one.
     */
    private function spentLink(): JsonResponse
    {
        return response()->json(
            ['message' => 'This reset link has expired. Ask for a new one.'],
            422,
        );
    }

    private function findByIdentifier(string $identifier): ?User
    {
        $identifier = mb_strtolower(trim($identifier));

        return User::query()
            ->whereRaw('lower(username) = ?', [$identifier])
            ->orWhereRaw('lower(email) = ?', [$identifier])
            ->first();
    }
}
