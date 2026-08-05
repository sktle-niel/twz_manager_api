<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SignIn;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SignInLogController extends Controller
{
    /** GET /api/accounts/{accountId}/sign-ins — self for a manager, anyone for the owner */
    public function index(Request $request, string $accountId): JsonResponse
    {
        $user = $request->user();
        if (! $user->isOwner() && (string) $user->id !== $accountId) {
            return response()->json(['message' => 'You do not have access to that.'], 403);
        }

        $current = $request->session()->getId();

        return response()->json(
            SignIn::query()
                ->where('user_id', $accountId)
                ->orderByDesc('at')
                ->limit(15)
                ->get()
                ->map(fn (SignIn $row) => [
                    'id' => $row->id,
                    'device' => $row->device,
                    'platform' => $row->platform,
                    'kind' => $row->kind,
                    'ip' => $row->ip,
                    'place' => $row->place,
                    'at' => $row->at->toIso8601ZuluString(),
                    // A fact, not a heuristic: the row remembers its session
                    'current' => $row->session_id === $current,
                ]),
        );
    }
}
