<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Which bank a branch deposits to — "bdo" or "bpi" (docs/API.md, GET /stores
 * and PATCH /stores/{id}). Most branches bank at BDO Network Bank and file its
 * transaction slip; one deposits to BPI, whose teller hands back a
 * deposit/payment receipt. The manager's slip-photo check reads for the
 * chosen bank's own form, so this is the owner's setting, per branch, with
 * BDO as the default for every row that existed before it was stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->string('bank', 8)->default('bdo')->after('timezone');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn('bank');
        });
    }
};
