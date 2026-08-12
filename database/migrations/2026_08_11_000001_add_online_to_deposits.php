<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Not every sale reaches the till as cash: GCash and bank-transfer payments
 * land in the account directly and never appear on a deposit slip. `online`
 * is the manager's declaration of that money for the covered days, so the
 * reconciliation can ask the honest question — cash deposited PLUS what came
 * in online against the expected total — instead of reading every online
 * payment as a shortfall.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deposits', function (Blueprint $table) {
            $table->decimal('online', 12, 2)->default(0)->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('deposits', function (Blueprint $table) {
            $table->dropColumn('online');
        });
    }
};
