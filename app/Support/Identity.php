<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Storage;

/*
 * Shapes the session payload exactly as docs/API.md (frontend repo) states:
 * { manager: Manager|null, owner: Owner|null } — exactly one set when signed
 * in, both null when anonymous. Ids cross the wire as strings.
 */
class Identity
{
    /** @return array{manager: ?array<string, mixed>, owner: ?array<string, mixed>} */
    public static function session(?User $user): array
    {
        if ($user === null) {
            return ['manager' => null, 'owner' => null];
        }

        return $user->isOwner()
            ? ['manager' => null, 'owner' => self::owner($user)]
            : ['manager' => self::manager($user), 'owner' => null];
    }

    /** @return array<string, mixed> */
    public static function manager(User $user): array
    {
        return [
            'id' => (string) $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'storeId' => $user->store_id,
            'active' => $user->active,
            'photoUrl' => self::photoUrl($user),
            'avatarKind' => $user->avatar_kind ?? 'girl',
        ];
    }

    /** @return array<string, mixed> */
    public static function owner(User $user): array
    {
        return [
            'id' => (string) $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'photoUrl' => self::photoUrl($user),
            'avatarKind' => $user->avatar_kind ?? 'girl',
        ];
    }

    /* Same door as every stored image: session-guarded /api/files — there is
       no public disk, so Storage::url would point at nothing */
    private static function photoUrl(User $user): ?string
    {
        return $user->photo_path ? '/api/files/'.$user->photo_path : null;
    }
}
