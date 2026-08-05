<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** One audited day one deposit covers — the (store, day) pair is unique */
#[Fillable(['deposit_id', 'store_id', 'day'])]
class DepositDay extends Model
{
    public $timestamps = false;

    protected $primaryKey = null;

    public $incrementing = false;
}
