<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

/*
 * The response idioms every endpoint shares — one wording, one place, so the
 * frontend can match on messages that never drift apart.
 */
abstract class Controller
{
    protected function forbidden(): JsonResponse
    {
        return response()->json(['message' => 'You do not have access to that.'], 403);
    }

    protected function notFound(string $message): JsonResponse
    {
        return response()->json(['message' => $message], 404);
    }

    /**
     * The shape the forms know how to highlight: one line per field.
     *
     * @param  array<string, string>  $fields
     */
    protected function fieldErrors(
        array $fields,
        int $status = 422,
        string $message = 'Check the highlighted fields.',
    ): JsonResponse {
        return response()->json(['message' => $message, 'fields' => $fields], $status);
    }
}
