<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'session_id', 'device', 'platform', 'kind', 'ip', 'place', 'at'])]
class SignIn extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return ['at' => 'datetime'];
    }
}
