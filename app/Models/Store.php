<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['id', 'name'])]
class Store extends Model
{
    /** Slug primary key ("arevalo"), assigned, never auto-incremented */
    public $incrementing = false;

    protected $keyType = 'string';

    public function managers(): HasMany
    {
        return $this->hasMany(User::class)->where('role', 'manager');
    }
}
