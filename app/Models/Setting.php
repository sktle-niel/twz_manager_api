<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One named value the owner can change, for the handful of settings that are
 * not worth a column of their own. Keyed by name rather than an id, because
 * the name is the identity — there is only ever one row per setting.
 */
class Setting extends Model
{
    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['key', 'value'];

    public static function read(string $key): ?string
    {
        return static::query()->find($key)?->value;
    }

    public static function write(string $key, string $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
