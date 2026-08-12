<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** One browser's push mailbox, owned by the account that subscribed on it */
#[Fillable(['user_id', 'endpoint', 'p256dh', 'auth'])]
class PushSubscription extends Model
{
    use HasUlids;
}
