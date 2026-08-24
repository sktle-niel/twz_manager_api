<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FailedSignIn;
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
            /* The throttle stops a guessing run; this is what lets the owner
               find out it happened. Nothing here reaches the response — the
               person knocking still gets one message either way. */
            $this->recordFailure($request, $identifier, $user !== null);

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

        /*
         * Thirty days, against Laravel's default of four hundred.
         *
         * The remembered cookie is the longest-lived credential this app
         * mints, and the box that asks for it is ticked by default, so it is
         * what actually decides how long a phone left in a tricycle stays
         * useful to whoever finds it. Short enough to bound that; long enough
         * that a shop phone is not asking for a password every week, which is
         * how passwords end up written under the counter.
         */
        Auth::guard('web')->setRememberDuration(43200);
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
    /**
     * An attempt that failed, kept for the owner. No password, hashed or
     * otherwise, and the identifier is truncated because the field accepts
     * whatever was typed into it.
     */
    private function recordFailure(Request $request, string $identifier, bool $known): void
    {
        FailedSignIn::query()->create([
            'identifier' => mb_substr($identifier, 0, 120),
            'ip' => (string) $request->ip(),
            ...DeviceName::parse($request->userAgent()),
            'known' => $known,
            'at' => now(),
        ]);

        /* Bounded the way the device log is, so a patient guessing run cannot
           fill a shared host's disk with its own history */
        FailedSignIn::query()
            ->orderByDesc('at')
            ->skip(200)->take(500)
            ->pluck('id')
            ->each(fn (string $old) => FailedSignIn::query()->whereKey($old)->delete());
    }

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
