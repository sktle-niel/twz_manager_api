<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Accounts stop having email addresses.
 *
 * Nobody at a branch signs in with one, nothing is mailed to one, and a
 * recovery now runs through the owner rather than an inbox — so the column
 * was a field people had to invent a value for and nothing ever read. The
 * password_reset_tokens table goes with it: the flow it backed no longer
 * exists.
 *
 * The settings table arrives in its place: one row per named value, for the
 * handful of things an owner can change that are not worth a column of their
 * own. The reset PIN is its first tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value');
            $table->timestamps();
        });

        Schema::dropIfExists('password_reset_tokens');

        /* The unique index has to go first and on its own. SQLite rebuilds the
           table to drop a column and carries the old index definitions across
           as it does, so an index still naming `email` is left pointing at a
           column that no longer exists — and every later query fails on it. */
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_email_unique');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['email', 'email_verified_at']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            /* Nullable and unindexed on the way back: the rows that exist by
               now have no address to put here, and a unique index over a
               column full of nulls would only be a trap for the next person. */
            $table->string('email')->nullable()->after('name');
            $table->timestamp('email_verified_at')->nullable()->after('email');
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::dropIfExists('settings');
    }
};
