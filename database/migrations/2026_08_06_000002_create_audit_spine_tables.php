<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The rest of the audit spine: what the branch spent (expenses, with their
 * receipt photos), what it deposited (deposits, and the audited days each
 * one covers), and who opened the account from where (sign_ins).
 *
 * Money is decimal(12,2) — never floats — and days are the branch-local
 * YYYY-MM-DD strings the whole contract runs on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_categories', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            // Company-covered: logged without a receipt photo
            $table->boolean('receipt_exempt')->default(false);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('store_id')->index();
            $table->string('day', 10);
            // The category NAME travels on the expense: a later rename or
            // deletion must never rewrite what a past day recorded
            $table->string('category');
            $table->string('note');
            $table->decimal('amount', 12, 2);
            $table->dateTime('at');
            $table->timestamps();

            $table->index(['store_id', 'day']);
        });

        Schema::create('expense_photos', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('expense_id')->index();
            $table->string('path');
            $table->timestamps();
        });

        Schema::create('deposits', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('store_id')->index();
            // The day the deposit was made, not the days it covers
            $table->string('day', 10);
            $table->decimal('amount', 12, 2);
            $table->string('reference');
            $table->string('slip_path');
            $table->string('slip_sha', 64)->nullable()->index();
            $table->boolean('matched')->default(false);
            $table->text('discrepancy_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('deposit_days', function (Blueprint $table) {
            $table->foreignUlid('deposit_id');
            $table->string('store_id');
            $table->string('day', 10);
            $table->primary(['store_id', 'day']); // One deposit covers a day, ever
            $table->index('deposit_id');
        });

        Schema::create('sign_ins', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->index();
            // Ties a row to the live session, so "this device" is a fact
            $table->string('session_id')->index();
            $table->string('device');
            $table->string('platform');
            $table->string('kind', 10); // phone | computer
            $table->string('ip', 45);
            $table->string('place')->default('');
            $table->dateTime('at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sign_ins');
        Schema::dropIfExists('deposit_days');
        Schema::dropIfExists('deposits');
        Schema::dropIfExists('expense_photos');
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('expense_categories');
    }
};
