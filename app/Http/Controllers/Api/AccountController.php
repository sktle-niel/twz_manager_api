<?php

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ReadsMultipart;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Identity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

/*
 * An account editing its own face: name, username, stock avatar, and the
 * optional photo (docs/API.md, PATCH /account). JSON when nothing was
 * attached, multipart (`payload` + `photo[]`) when a new photo rides along —
 * and a new photo always wins over `removePhoto`. The answer is the refreshed
 * Session, so the client swaps its identity in one move.
 */
class AccountController extends Controller
{
    use ReadsMultipart;

    /** PATCH /api/account — JSON, or multipart when a photo is attached */
    public function update(Request $request): JsonResponse
    {
        $patch = $request->isJson() ? $request->all() : $this->payload($request);

        Validator::make(
            $patch,
            [
                'name' => ['required', 'string', 'max:80'],
                'username' => ['required', 'string', 'max:60', 'regex:/^[A-Za-z0-9._-]+$/'],
                'avatarKind' => ['required', 'in:girl,boy'],
                'removePhoto' => ['sometimes', 'boolean'],
            ],
            [
                'username.regex' => 'Letters, numbers, dots, dashes and underscores only.',
            ],
        )->validate();

        $user = $request->user();

        $username = mb_strtolower(trim($patch['username']));
        $taken = User::query()
            ->whereRaw('lower(username) = ?', [$username])
            ->whereKeyNot($user->id)
            ->exists();
        if ($taken) {
            return $this->fieldErrors(['username' => 'That username is taken.']);
        }

        $photo = $this->files($request, 'photo')[0] ?? null;
        $oldPath = $user->photo_path;
        if ($photo !== null) {
            /* Sniffed content type, not the client's claim — these files are
               served inline on the app's own origin, so an HTML or SVG body
               smuggled in as a "photo" would run as the app */
            $image = in_array($photo->getMimeType(), [
                'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/heic', 'image/heif',
            ], true) && $photo->getSize() <= 10 * 1024 * 1024;
            if (! $image) {
                return $this->fieldErrors([
                    'photo' => 'Use a photo file (JPG, PNG, or WebP) under 10 MB.',
                ]);
            }
            $stored = $photo->store("avatars/{$user->id}");
            if ($stored !== false) {
                $user->photo_path = $stored;
            }
        } elseif ($patch['removePhoto'] ?? false) {
            $user->photo_path = null;
        }

        $user->fill([
            'name' => trim($patch['name']),
            'username' => $username,
            'avatar_kind' => $patch['avatarKind'],
        ])->save();

        // The old file goes only after the row safely points away from it
        if ($oldPath !== null && $oldPath !== $user->photo_path) {
            Storage::delete($oldPath);
        }

        return response()->json(Identity::session($user));
    }
}
