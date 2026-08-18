<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['store_id', 'day', 'amount', 'online', 'expected', 'slip_path', 'slip_sha', 'matched', 'discrepancy_reason'])]
class Deposit extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'online' => 'decimal:2',
            'expected' => 'decimal:2',
            'matched' => 'boolean',
        ];
    }

    public function days(): HasMany
    {
        return $this->hasMany(DepositDay::class);
    }

    /** @return array<string, mixed> The wire shape of docs/API.md */
    public function toWire(): array
    {
        return [
            'id' => $this->id,
            'storeId' => $this->store_id,
            'day' => $this->day,
            'amount' => (float) $this->amount,
            'online' => (float) $this->online,
            /* The judged-against sum, frozen at recording; null on rows from
               before it was stored */
            'expected' => $this->expected !== null ? (float) $this->expected : null,
            'covers' => $this->days->pluck('day')->sort()->values(),
            'slipUrl' => "/api/files/{$this->slip_path}",
            'matched' => $this->matched,
        ];
    }
}
