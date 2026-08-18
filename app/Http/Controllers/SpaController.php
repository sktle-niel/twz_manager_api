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

        /* A file that is not there is a 404, never the app shell. Answering a
           missing hashed chunk with app.html — status 200, Content-Type
           text/html — is what bricks an installed PWA the first time it
           updates: a browser still running yesterday's build asks for
           /assets/DashboardPage-OLDHASH.js, is handed a page of HTML, and
           fails to parse it as a module. The route then dies with no way back.
           Every SPA path is extension-less; anything carrying one is a file
           that Apache already served if it exists. */
        if (preg_match('/\.[A-Za-z0-9]+$/', $request->path()) === 1) {
            abort(404);
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
