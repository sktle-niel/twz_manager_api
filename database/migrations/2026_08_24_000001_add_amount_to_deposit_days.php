<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The column DepositController has been writing since "Track partial deposit
 * amounts by day" (8bbd5ee) — that commit shipped the controller, the model
 * cast and the ledger read, but no migration.
 *
 * On any database built from this repo the DepositDay insert therefore threw
 * "Unknown column 'amount' in 'field list'" inside the recording transaction,
 * rolling the whole deposit back and stranding the slip photo: no deposit
 * could be filed at all, and the deposit tests fail the same way. The
 * development database only worked because the column was added there by hand.
 *
 * Nullable on purpose: rows written before the feature carry no per-day share,
 * and AuditLedger already reads a missing value as null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deposit_days', function (Blueprint $table) {
            if (! Schema::hasColumn('deposit_days', 'amount')) {
                $table->decimal('amount', 12, 2)->nullable()->after('day');
            }
        });
    }

    public function down(): void
    {
        Schema::table('deposit_days', function (Blueprint $table) {
            if (Schema::hasColumn('deposit_days', 'amount')) {
                $table->dropColumn('amount');
            }
        });
    }
};
