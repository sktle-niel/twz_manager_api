<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One line of one Loyverse receipt. Amounts and quantities are signed, so a
 * refund line subtracts itself from any range sum — the same convention
 * `receipts` uses.
 *
 * `excluded` is the ingest-time verdict on whether the SKU was services or
 * labor. It is stored rather than recomputed so this table can never tell a
 * different story from the `receipts.gross` a deposit was matched against.
 */
#[Fillable([
    'receipt_number', 'store_id', 'day', 'sku', 'name',
    'quantity', 'gross', 'cost', 'excluded',
])]
class ReceiptItem extends Model
{
    /** Rows are written in bulk by the sync and never touched again */
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'gross' => 'decimal:2',
            'cost' => 'decimal:2',
            'excluded' => 'boolean',
        ];
    }
}
