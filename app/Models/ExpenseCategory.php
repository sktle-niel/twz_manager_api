<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** What managers pick from; the owner edits the list as one whole */
#[Fillable(['id', 'name', 'receipt_exempt', 'position'])]
class ExpenseCategory extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['receipt_exempt' => 'boolean'];
    }
}
