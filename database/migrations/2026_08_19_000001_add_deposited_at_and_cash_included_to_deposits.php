<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('deposits', function (Blueprint $table) {
            if (! Schema::hasColumn('deposits', 'deposited_at')) {
                $table->timestamp('deposited_at')->nullable()->after('day');
            }
            if (! Schema::hasColumn('deposits', 'cash_included_last_day')) {
                $table->decimal('cash_included_last_day', 12, 2)->nullable()->after('online');
            }
        });
    }

    public function down()
    {
        Schema::table('deposits', function (Blueprint $table) {
            if (Schema::hasColumn('deposits', 'cash_included_last_day')) {
                $table->dropColumn('cash_included_last_day');
            }
            if (Schema::hasColumn('deposits', 'deposited_at')) {
                $table->dropColumn('deposited_at');
            }
        });
    }
};
