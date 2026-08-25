<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['id', 'name', 'bank'])]
class Store extends Model
{
    /** The banks a branch can deposit to; the slip check reads for the chosen one's form */
    public const BANK_BDO = 'bdo';

    public const BANK_BPI = 'bpi';

    /** @var list<string> */
    public const BANKS = [self::BANK_BDO, self::BANK_BPI];

    /** Slug primary key ("arevalo"), assigned, never auto-incremented */
    public $incrementing = false;

    protected $keyType = 'string';

    public function managers(): HasMany
    {
        return $this->hasMany(User::class)->where('role', 'manager');
    }

    /**
     * The wire shape of docs/API.md's `Store`.
     *
     * @return array{id: string, name: string, bank: string}
     */
    public function toWire(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            /* Defaulted at the column, but a row written around the migration
               could still carry null; the client reads a missing bank as BDO
               and so does this */
            'bank' => $this->bank ?? self::BANK_BDO,
        ];
    }
}
