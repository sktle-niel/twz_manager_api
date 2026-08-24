<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One sign-in attempt that did not work.
 *
 * `known` says whether the username exists, which is the difference between
 * somebody fat-fingering their own name and somebody working through a real
 * account. It is never told to the person attempting — the sign-in form gives
 * one message for both — only to the owner, afterwards.
 */
#[Fillable(['identifier', 'ip', 'device', 'platform', 'kind', 'known', 'at'])]
class FailedSignIn extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'at' => 'datetime',
            'known' => 'boolean',
        ];
    }

    /** @return array<string, mixed> */
    public function toWire(): array
    {
        return [
            'id' => $this->id,
            'identifier' => $this->identifier,
            'ip' => $this->ip,
            'device' => $this->device,
            'platform' => $this->platform,
            'kind' => $this->kind,
            'known' => $this->known,
            'at' => $this->at->toIso8601ZuluString(),
        ];
    }
}
