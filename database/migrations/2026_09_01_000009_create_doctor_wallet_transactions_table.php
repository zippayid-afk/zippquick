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
        if (!Schema::hasTable('doctor_wallet_transactions')) {
            Schema::create('doctor_wallet_transactions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('doctor_id')->constrained('doctors')->cascadeOnDelete();
                $table->foreignId('appointment_id')->nullable()->constrained('appointments')->nullOnDelete();
                $table->enum('type', ['credit', 'debit'])->default('credit');
                $table->decimal('amount', 12, 2);
                $table->string('currency')->default('USD');
                $table->string('currency_code')->default('USD');
                $table->decimal('balance_before', 12, 2);
                $table->decimal('balance_after', 12, 2);
                $table->dateTime('transaction_date');
                $table->enum('status', ['completed', 'pending', 'failed'])->default('completed');
                $table->longText('message')->nullable();
                $table->string('txn_id')->nullable();
                $table->string('reference_id')->nullable();
                $table->foreignId('country_id')->constrained('countries')->cascadeOnDelete();
                $table->timestamps();
                $table->index(['doctor_id', 'transaction_date']);
                $table->index(['type', 'status']);
                $table->index('transaction_date');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('doctor_wallet_transactions');
    }
};
