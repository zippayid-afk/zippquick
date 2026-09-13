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
        if (!Schema::hasTable('doctor_time_slots')) {
            Schema::create('doctor_time_slots', function (Blueprint $table) {
                $table->id();
                $table->foreignId('doctor_id')->constrained('doctors')->cascadeOnDelete();
                $table->foreignId('clinic_id')->nullable()->constrained('clinics')->nullOnDelete();
                $table->enum('day_of_week', ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday']);
                $table->time('start_time');
                $table->time('end_time');
                $table->integer('slot_duration_minutes')->default(30);
                $table->integer('max_patients_per_slot')->default(1);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->unique(['doctor_id', 'day_of_week', 'start_time'], 'unique_doctor_slot');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('doctor_time_slots');
    }
};
