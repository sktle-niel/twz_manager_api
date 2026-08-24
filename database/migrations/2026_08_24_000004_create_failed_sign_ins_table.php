<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Sign-ins that did not work.
 *
 * `sign_ins` records the doors that opened; nothing recorded the ones somebody
 * rattled. The throttle at SessionController stops a guessing run after five
 * tries, but stopping it silently means the owner never learns it happened —
 * and somebody methodically trying a manager's name from a strange address is
 * exactly the thing worth knowing about in a business where a branch account
 * can move money records.
 *
 * A separate table on purpose: a row in `sign_ins` means a device is signed
 * in, and blurring that with attempts would make the device list lie.
 *
 * No user_id, because the whole point is that the attempt matched no account
 * — and obviously no password, not even hashed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('failed_sign_ins', function (Blueprint $table) {
            $table->ulid('id')->primary();
            /* What they typed, capped: an identifier field accepts anything */
            $table->string('identifier', 120);
            $table->string('ip', 45);
            $table->string('device');
            $table->string('platform');
            $table->string('kind', 10);
            /* True when the username exists — the owner's real signal, since
               a run against a real account is worth more attention than one
               against a name nobody has */
            $table->boolean('known')->default(false);
            $table->dateTime('at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('failed_sign_ins');
    }
};
