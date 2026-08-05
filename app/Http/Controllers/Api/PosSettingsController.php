<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Loyverse\LoyverseAuthException;
use App\Services\Loyverse\LoyverseBudgetExhausted;
use App\Services\Loyverse\LoyverseException;
use App\Services\Loyverse\LoyverseNotConfigured;
use App\Services\Loyverse\LoyverseStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class PosSettingsController extends Controller
{
    public function __construct(private readonly LoyverseStatus $status) {}

    /** GET /api/settings/pos */
    public function show(): JsonResponse
    {
        return response()->json($this->status->summary());
    }

    /** POST /api/settings/pos/reconnect — the deliberate, loud validation */
    public function reconnect(): Response|JsonResponse
    {
        try {
            $this->status->revalidate();
        } catch (LoyverseNotConfigured) {
            return response()->json(
                ['message' => 'No Loyverse token is set on the server yet. Add it to the backend configuration first.'],
                422,
            );
        } catch (LoyverseAuthException) {
            return response()->json(
                ['message' => 'Loyverse rejected the token. It may have been deleted. Create a new one and update the server.'],
                422,
            );
        } catch (LoyverseBudgetExhausted $e) {
            return response()->json(
                ['message' => "The Loyverse request budget is spent just now. Try again in about {$e->retryAfterSeconds} seconds."],
                429,
            );
        } catch (LoyverseException) {
            return response()->json(
                ['message' => 'Loyverse could not be reached. Check the connection and try again.'],
                502,
            );
        }

        return response()->noContent();
    }
}
