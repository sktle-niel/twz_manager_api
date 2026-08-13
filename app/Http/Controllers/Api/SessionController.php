<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SignIn;
use App\Models\User;
use App\Support\DeviceName;
use App\Support\Identity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

class SessionController extends Controller
{
    /** GET /api/session — anonymous is an answer, never an error */
    public function show(Request $request): JsonResponse
    {
        return response()->json(Identity::session($request->user()));
    }

    /** POST /api/session */
    public function store(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'identifier' => ['required', 'string'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        $identifier = mb_strtolower(trim($credentials['identifier']));

        /* Five failures per minute per identifier+IP before the door pauses.
           Only failures count — a shop device signing three managers in and
           out all day must never trip this. */
        $throttleKey = 'login:'.$identifier.'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            return response()->json(
                ['message' => "Too many attempts. Try again in {$seconds} seconds."],
                429,
            );
        }

        /* Username only. Accounts have no email address to sign in with, and
           the one field keeps the sign-in form honest about what it wants. */
        $user = User::query()
            ->whereRaw('lower(username) = ?', [$identifier])
            ->first();

        /* One message for a wrong username and a wrong password alike — the
           form must not reveal which accounts exist */
        if ($user === null || ! Hash::check($credentials['password'], $user->password)) {
            RateLimiter::hit($throttleKey, 60);

            return response()->json(
                ['message' => 'That username and password do not match.'],
                401,
            );
        }

        RateLimiter::clear($throttleKey);

        if (! $user->active) {
            return response()->json(
                ['message' => 'This account is disabled. Contact the owner.'],
                403,
            );
        }

        Auth::guard('web')->login($user, (bool) ($credentials['remember'] ?? false));
        // A fresh id on every sign-in, so a pre-auth cookie cannot be fixated
        $request->session()->regenerate();

        $this->recordSignIn($request, $user);

        return response()->json(Identity::session($user));
    }

    /** DELETE /api/session */
    public function destroy(Request $request): Response
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    /*
     * The sign-in log: which device, from where, tied to this session so
     * "this device" is a fact later. The same device on the same IP is one
     * line, not a diary — a repeat sign-in refreshes that line's time (and
     * session id, so "this device" follows) instead of stacking a new entry
     * every shop morning. Kept short — the last 15 tell the story.
     */
    private function recordSignIn(Request $request, User $user): void
    {
        $named = DeviceName::parse($request->userAgent());
        $ip = (string) $request->ip();

        $repeat = SignIn::query()
            ->where('user_id', $user->id)
            ->where('ip', $ip)
            ->where('device', $named['device'])
            ->first();

        if ($repeat !== null) {
            $repeat->update([
                'session_id' => $request->session()->getId(),
                'at' => now(),
            ]);

            return;
        }

        SignIn::query()->create([
            'user_id' => $user->id,
            'session_id' => $request->session()->getId(),
            ...$named,
            'ip' => $ip,
            'place' => '',
            'at' => now(),
        ]);
        SignIn::query()
            ->where('user_id', $user->id)
            ->orderByDesc('at')
            ->skip(15)->take(100)
            ->pluck('id')
            ->each(fn (string $old) => SignIn::query()->whereKey($old)->delete());
    }
}
