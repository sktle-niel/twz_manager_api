<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PasswordResetController extends Controller
{
    /**
     * POST /api/password-resets — resolves 204 whether or not the account
     * exists, so the endpoint cannot be used to enumerate accounts.
     *
     * Sending the actual reset email is wired up with the mail configuration
     * later; accepting the request now keeps the frontend contract honest.
     */
    public function store(Request $request): Response
    {
        $request->validate(['identifier' => ['required', 'string']]);

        return response()->noContent();
    }
}
