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
            'email' => $user->email,
            'storeId' => $user->store_id,
            'active' => $user->active,
            'photoUrl' => self::photoUrl($user),
        ];
    }

    /** @return array<string, mixed> */
    public static function owner(User $user): array
    {
        return [
            'id' => (string) $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'photoUrl' => self::photoUrl($user),
        ];
    }

    private static function photoUrl(User $user): ?string
    {
        return $user->photo_path ? Storage::url($user->photo_path) : null;
    }
}
