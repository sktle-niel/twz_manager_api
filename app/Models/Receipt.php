<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One Loyverse receipt, reduced to what the daily audit needs. Amounts are
 * signed: refunds carry negative gross and cost, so day totals are plain
 * sums. Cancelled receipts stay in the table (they arrive as updates and
 * must overwrite their old selves) but every aggregation excludes them.
 */
#[Fillable([
    'receipt_number', 'store_id', 'type', 'day', 'hour', 'receipt_date',
    'gross', 'cost', 'cancelled', 'loyverse_updated_at',
])]
class Receipt extends Model
{
    /** Loyverse's receipt_number is the identity — strings like "3-1009" */
    protected $primaryKey = 'receipt_number';

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'receipt_date' => 'datetime',
            'loyverse_updated_at' => 'datetime',
            'gross' => 'decimal:2',
            'cost' => 'decimal:2',
            'cancelled' => 'boolean',
        ];
    }
}
