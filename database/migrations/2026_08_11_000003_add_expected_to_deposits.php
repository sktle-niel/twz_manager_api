<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * What this deposit was judged against, frozen at recording time: the sum of
 * expected over every day it covers. Stored, because a deposit covering six
 * days cannot be honestly compared to any single day's figure — and because
 * the judgement must not drift if a day's numbers are ever recomputed.
 *
 * Matched rows are backfilled (matched means cash plus online equalled the
 * expected exactly); unmatched old rows stay null and the pages fall back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deposits', function (Blueprint $table) {
            $table->decimal('expected', 12, 2)->nullable()->after('online');
        });

        DB::table('deposits')
            ->where('matched', true)
            ->update(['expected' => DB::raw('amount + online')]);
    }

    public function down(): void
    {
        Schema::table('deposits', function (Blueprint $table) {
            $table->dropColumn('expected');
        });
    }
};
