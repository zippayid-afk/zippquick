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
        if (!Schema::hasTable('appointments')) {
            Schema::create('appointments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('doctor_id')->constrained('doctors')->cascadeOnDelete();
                $table->foreignId('patient_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('clinic_id')->nullable()->constrained('clinics')->nullOnDelete();
                $table->date('appointment_date');
                $table->dateTime('appointment_time');
                $table->integer('duration_minutes')->default(30);
                $table->enum('type', ['clinic', 'video', 'in_house'])->default('clinic');
                $table->enum('status', ['pending', 'approved', 'completed', 'cancelled', 'rejected'])->default('pending');
                $table->string('patient_name');
                $table->string('patient_email');
                $table->string('patient_phone');
                $table->longText('symptoms')->nullable();
                $table->longText('notes')->nullable();
                $table->string('google_meet_link')->nullable();
                $table->string('google_meet_link')->nullable();
                $table->string('google_meet_id')->nullable();
                $table->boolean('is_rescheduled')->default(false);
                $table->foreignId('original_appointment_id')->nullable()->constrained('appointments')->nullOnDelete();
                $table->text('cancellation_reason')->nullable();
                $table->decimal('amount', 10, 2)->nullable();
                $table->string('currency')->default('USD');
                $table->enum('payment_status', ['pending', 'completed', 'failed'])->default('pending');
                $table->softDeletes();
                $table->timestamps();
                $table->index(['doctor_id', 'status']);
                $table->index(['patient_id', 'appointment_date']);
                $table->index(['appointment_date', 'status']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};

