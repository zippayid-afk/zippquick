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
        if (!Schema::hasTable('doctor_ratings')) {
            Schema::create('doctor_ratings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('doctor_id')->constrained('doctors')->cascadeOnDelete();
                $table->foreignId('appointment_id')->nullable()->constrained('appointments')->nullOnDelete();
                $table->foreignId('patient_id')->constrained('users')->cascadeOnDelete();
                $table->integer('rating')->between(1, 5);
                $table->longText('review')->nullable();
                $table->boolean('is_verified_appointment')->default(true);
                $table->timestamps();
                $table->index(['doctor_id', 'rating']);
                $table->index('patient_id');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('doctor_ratings');
    }
};
