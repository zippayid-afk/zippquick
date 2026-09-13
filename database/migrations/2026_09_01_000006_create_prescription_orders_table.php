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
        if (!Schema::hasTable('prescription_orders')) {
            Schema::create('prescription_orders', function (Blueprint $table) {
                $table->id();
                $table->foreignId('prescription_id')->constrained('prescriptions')->cascadeOnDelete();
                $table->string('pharmacy_id')->nullable();
                $table->enum('order_status', ['pending', 'approved', 'fulfilled', 'cancelled'])->default('pending');
                $table->dateTime('ordered_date');
                $table->dateTime('fulfilled_date')->nullable();
                $table->decimal('total_amount', 10, 2)->nullable();
                $table->string('currency')->default('USD');
                $table->text('delivery_address')->nullable();
                $table->string('delivery_phone')->nullable();
                $table->longText('notes')->nullable();
                $table->softDeletes();
                $table->timestamps();
                $table->index(['prescription_id', 'order_status']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('prescription_orders');
    }
};
