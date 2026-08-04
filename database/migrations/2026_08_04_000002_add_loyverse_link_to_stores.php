<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The branch's link to its Loyverse store, and its own timezone.
 *
 * Loyverse's store ids are theirs; ours are ours (docs/LOYVERSE.md) — a
 * branch can be renamed or re-linked without rewriting history. The timezone
 * travels with the branch because the audit day is the branch's calendar
 * day: receipts bucket by receipt_date in this zone, never in UTC.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->string('loyverse_store_id')->nullable()->unique()->after('name');
            $table->string('timezone')->default('Asia/Manila')->after('loyverse_store_id');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn(['loyverse_store_id', 'timezone']);
        });
    }
};
