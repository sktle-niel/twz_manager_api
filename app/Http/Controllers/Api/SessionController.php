<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Identity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

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
        $user = User::query()
            ->whereRaw('lower(username) = ?', [$identifier])
            ->orWhereRaw('lower(email) = ?', [$identifier])
            ->first();

        /* One message for a wrong user and a wrong password alike — the form
           must not reveal which accounts exist */
        if ($user === null || ! Hash::check($credentials['password'], $user->password)) {
            return response()->json(
                ['message' => 'That username and password do not match.'],
                401,
            );
        }

        if (! $user->active) {
            return response()->json(
                ['message' => 'This account is disabled. Contact the owner.'],
                403,
            );
        }

        Auth::guard('web')->login($user, (bool) ($credentials['remember'] ?? false));
        // A fresh id on every sign-in, so a pre-auth cookie cannot be fixated
        $request->session()->regenerate();

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
}
