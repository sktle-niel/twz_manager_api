<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

/*
 * The PIN that stands between a signed-in owner and somebody else's password.
 *
 * It lives in two places by design. config('twz.reset_pin') is the value a
 * fresh install starts with, so the first owner has a PIN without anyone
 * having seeded one. The moment it is changed, a hash lands in the settings
 * table and takes over for good — the .env value is never consulted again.
 * Only the hash is ever stored, so the PIN cannot be read back out of the
 * database, only checked against.
 */
class ResetPin
{
    private const KEY = 'admin_reset_pin_hash';

    /** Exactly what a person can be asked to remember and photograph */
    public const LENGTH = 4;

    public static function matches(string $pin): bool
    {
        $stored = Setting::read(self::KEY);

        if ($stored === null) {
            return hash_equals(config('twz.reset_pin'), $pin);
        }

        return Hash::check($pin, $stored);
    }

    public static function change(string $pin): void
    {
        Setting::write(self::KEY, Hash::make($pin));
    }

    /**
     * Whether the PIN is still the one shipped in .env — including the case
     * where somebody changed it back to that value. The owner's settings page
     * says so out loud, because a documented default is not a secret.
     */
    public static function isDefault(): bool
    {
        return self::matches(config('twz.reset_pin'));
    }

    public static function changedAt(): ?Carbon
    {
        return Setting::query()->find(self::KEY)?->updated_at;
    }
}
