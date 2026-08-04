<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The TWZ domain on top of Laravel's stock users table.
 *
 * Store ids are slugs ("arevalo"), not auto-increments: the frontend treats
 * them as opaque strings, they read well in URLs and logs, and they cannot
 * collide with a row number from some other table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stores', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('username')->unique()->after('name');
            // One owner, many managers — a plain string is honest and queryable
            $table->string('role')->default('manager')->after('email');
            /* Indexed string, no DB-level FK: SQLite cannot add one via ALTER,
               and the one-manager-one-branch rule lives in the app layer anyway */
            $table->string('store_id')->nullable()->index()->after('role');
            $table->boolean('active')->default(true)->after('store_id');
            $table->string('photo_path')->nullable()->after('active');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['username', 'role', 'store_id', 'active', 'photo_path']);
        });
        Schema::dropIfExists('stores');
    }
};
