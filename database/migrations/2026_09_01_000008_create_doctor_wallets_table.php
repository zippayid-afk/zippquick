<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('doctor_wallets')) {
            Schema::create('doctor_wallets', function (Blueprint $table) {
                $table->id();
                $table->foreignId('doctor_id')->unique()->constrained('doctors')->cascadeOnDelete();
                $table->decimal('balance', 12, 2)->default(0);
                $table->decimal('total_earned', 12, 2)->default(0);
                $table->decimal('total_withdrawn', 12, 2)->default(0);
                $table->string('currency')->default('USD');
                $table->foreignId('country_id')->constrained('countries')->cascadeOnDelete();
                $table->timestamps();
                $table->index('balance');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('doctor_wallets');
    }
};
