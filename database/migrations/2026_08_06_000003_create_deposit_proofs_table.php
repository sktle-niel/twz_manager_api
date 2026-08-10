<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The receipts a manager attaches to explain a discrepancy, kept with the deposit */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deposit_proofs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('deposit_id')->index();
            $table->string('path');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deposit_proofs');
    }
};
