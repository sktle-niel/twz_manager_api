<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** Cash an employee drew against their pay — the drawer's money, on loan */
#[Fillable(['store_id', 'day', 'employee', 'amount', 'note', 'at'])]
class Advance extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'at' => 'datetime',
        ];
    }

    /** @return array<string, mixed> The wire shape of docs/API.md */
    public function toWire(): array
    {
        return [
            'id' => $this->id,
            'storeId' => $this->store_id,
            'day' => $this->day,
            'employee' => $this->employee,
            'amount' => (float) $this->amount,
            'note' => $this->note,
            'at' => $this->at->toIso8601ZuluString(),
        ];
    }
}
