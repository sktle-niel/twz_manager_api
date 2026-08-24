<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FailedSignIn;
use Illuminate\Http\JsonResponse;

/*
 * The attempts that did not work, newest first — the owner's only window onto
 * somebody trying accounts they do not have.
 *
 * Owner only, and deliberately: `known` tells you whether a username exists,
 * which is precisely the fact the sign-in form refuses to reveal to whoever
 * is knocking.
 */
class FailedSignInController extends Controller
{
    /** How many the page shows; the table keeps a little more than this */
    private const RECENT = 25;

    /** GET /api/security/failed-sign-ins */
    public function index(): JsonResponse
    {
        return response()->json(
            FailedSignIn::query()
                ->orderByDesc('at')
                ->limit(self::RECENT)
                ->get()
                ->map(fn (FailedSignIn $row) => $row->toWire())
                ->values(),
        );
    }
}
