<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/*
 * Stored photos, served same-origin and behind the same session cookie as
 * everything else (docs/API.md "Stored images") — receipts and slips are
 * financial records, not public assets, so no public disk and no symlink.
 */
class FileController extends Controller
{
    /** GET /api/files/{path} */
    public function show(Request $request, string $path): Response
    {
        /* Only the receipts and avatars trees are servable, and no path
           segment may climb out of them */
        $servable = str_starts_with($path, 'receipts/') || str_starts_with($path, 'avatars/');
        if (! $servable || str_contains($path, '..')) {
            abort(404);
        }
        if (! Storage::exists($path)) {
            abort(404);
        }

        return Storage::response($path);
    }
}
