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
        if (!Schema::hasTable('prescriptions')) {
            Schema::create('prescriptions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('doctor_id')->constrained('doctors')->cascadeOnDelete();
                $table->foreignId('patient_id')->constrained('users')->cascadeOnDelete();
                $table->dateTime('prescription_date');
                $table->json('medications')->nullable();
                $table->longText('diagnosis')->nullable();
                $table->longText('clinical_notes')->nullable();
                $table->string('doctor_signature')->nullable();
                $table->string('seal_image')->nullable();
                $table->string('pdf_file')->nullable();
                $table->enum('status', ['draft', 'issued', 'fulfilled', 'cancelled'])->default('draft');
                $table->date('valid_until')->nullable();
                $table->longText('special_instructions')->nullable();
                $table->unsignedBigInteger('appointment_id')->nullable();
                $table->softDeletes();
                $table->timestamps();
                $table->index(['doctor_id', 'status']);
                $table->index(['patient_id', 'prescription_date']);
                $table->index('valid_until');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('prescriptions');
    }
};
