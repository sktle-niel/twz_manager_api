<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * GET /api/files/{path} now resolves a requested path back to the row that
 * registered it, so it can ask which branch owns the photo before serving it.
 * That lookup happens on every image the app renders — a History page full of
 * receipt thumbnails is dozens of them — so the columns it searches need to be
 * indexed or the check becomes three table scans per thumbnail.
 *
 * Guarded, because the deposits table is old enough to have been hand-edited
 * on at least one machine.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deposits', function (Blueprint $table) {
            $table->index('slip_path');
        });
        Schema::table('deposit_proofs', function (Blueprint $table) {
            $table->index('path');
        });
        Schema::table('expense_photos', function (Blueprint $table) {
            $table->index('path');
        });
    }

    public function down(): void
    {
        Schema::table('deposits', function (Blueprint $table) {
            $table->dropIndex(['slip_path']);
        });
        Schema::table('deposit_proofs', function (Blueprint $table) {
            $table->dropIndex(['path']);
        });
        Schema::table('expense_photos', function (Blueprint $table) {
            $table->dropIndex(['path']);
        });
    }
};
