<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One line a branch spent: the category name travels with it, frozen */
#[Fillable(['store_id', 'day', 'category', 'note', 'amount', 'at'])]
class Expense extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'at' => 'datetime',
        ];
    }

    public function photos(): HasMany
    {
        return $this->hasMany(ExpensePhoto::class);
    }

    /** @return array<string, mixed> The wire shape of docs/API.md */
    public function toWire(): array
    {
        return [
            'id' => $this->id,
            'storeId' => $this->store_id,
            'day' => $this->day,
            'category' => $this->category,
            'note' => $this->note,
            'amount' => (float) $this->amount,
            'at' => $this->at->toIso8601ZuluString(),
            'receiptUrls' => $this->photos->map(fn (ExpensePhoto $p) => "/api/files/{$p->path}")->all(),
        ];
    }
}
