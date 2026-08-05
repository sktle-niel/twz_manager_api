<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['store_id', 'day', 'amount', 'reference', 'slip_path', 'slip_sha', 'matched', 'discrepancy_reason'])]
class Deposit extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'matched' => 'boolean',
        ];
    }

    public function days(): HasMany
    {
        return $this->hasMany(DepositDay::class);
    }
}
