<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The lines behind a receipt, which until now were read once at ingest and
 * thrown away: `receipts` kept only the day's netted totals, so nothing in
 * the app could say WHAT was sold — only how much.
 *
 * `excluded` records the verdict at ingest, not at read time, exactly as
 * `receipts.gross` does: a line is services-and-labor if its SKU was on the
 * excluded list when it was pulled. Recomputing it per query against today's
 * list would let this page disagree with the figures every deposit was
 * matched against, which is the one thing that must never happen.
 *
 * Amounts and quantities are SIGNED, mirroring receipts: a REFUND line
 * carries negatives so a range is a plain SUM.
 *
 * `store_id` and `day` are denormalised off the parent receipt so the page's
 * date-range read never joins to filter — only to skip cancelled receipts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receipt_items', function (Blueprint $table) {
            $table->id();
            $table->string('receipt_number')->index();
            $table->string('store_id');
            $table->string('day', 10);

            /* Loyverse allows a line with no SKU; it still sold something */
            $table->string('sku')->nullable();
            $table->string('name');

            $table->decimal('quantity', 12, 3);
            $table->decimal('gross', 12, 2);
            $table->decimal('cost', 12, 2)->default(0);

            /* Services and labor: real money, but never part of net sales */
            $table->boolean('excluded')->default(false);

            $table->index(['store_id', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receipt_items');
    }
};
