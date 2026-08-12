<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Cash advances: money an employee draws from the drawer against their pay.
 * Not an expense — the shop gets it back — but on the day it is drawn the
 * cash is gone all the same, so the ledger subtracts it from that day's
 * expected deposit exactly like spend. Kept as its own table so expense
 * totals stay what the branch actually spent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('advances', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('store_id')->index();
            $table->string('day', 10);
            // A name, not an account — employees are not users of this app
            $table->string('employee');
            $table->decimal('amount', 12, 2);
            $table->string('note')->default('');
            $table->dateTime('at');
            $table->timestamps();

            $table->index(['store_id', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('advances');
    }
};
