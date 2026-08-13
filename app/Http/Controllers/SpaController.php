<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;

/*
 * Serves the built PWA. In production the frontend's build is staged into
 * public/ (its index.html renamed app.html, so it never shadows index.php),
 * and every non-API, non-file path lands here — the SPA router takes it from
 * the browser side. Unknown API paths stay JSON: a typo'd endpoint must not
 * be answered with a page of HTML.
 */
class SpaController extends Controller
{
    public function __invoke(Request $request): BinaryFileResponse|JsonResponse
    {
        if ($request->is('api/*')) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $app = public_path('app.html');
        if (! is_file($app)) {
            return response()->json(
                ['message' => 'The app build is not staged. See docs/DEPLOY.md.'],
                404,
            );
        }

        return response()->file($app);
    }
}
