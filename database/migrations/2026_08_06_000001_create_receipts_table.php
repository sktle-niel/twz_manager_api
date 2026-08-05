<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The local copy of Loyverse's receipts — the table every sales figure is
 * served from, so a page load never depends on Loyverse being up or the
 * request budget having room (docs/LOYVERSE.md: Loyverse is upstream, not a
 * live dependency of a render).
 *
 * Amounts are SIGNED: a REFUND receipt is stored with negative gross and
 * cost, so a day's total is a plain SUM and a refund dated after its sale
 * lands on the day the money actually left the drawer. `day` and `hour` are
 * the branch's own clock, computed once at ingest from receipt_date in the
 * store's timezone — never recomputed per query, never UTC.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receipts', function (Blueprint $table) {
            $table->string('receipt_number')->primary();
            $table->string('store_id')->index();
            $table->string('type', 10)->default('SALE');
            $table->string('day', 10);
            $table->unsignedTinyInteger('hour');
            $table->dateTime('receipt_date');
            $table->decimal('gross', 12, 2);
            $table->decimal('cost', 12, 2)->default(0);
            $table->boolean('cancelled')->default(false);
            $table->dateTime('loyverse_updated_at')->index();
            $table->timestamps();

            $table->index(['store_id', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receipts');
    }
};
