<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Which stock avatar an account shows while it has no photo — "girl" or
 * "boy", girl being the default for every account that never chose
 * (docs/API.md, PATCH /account). A real photo always wins over either.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('avatar_kind', 8)->default('girl');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('avatar_kind');
        });
    }
};
