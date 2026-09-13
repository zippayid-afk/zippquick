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
        if (!Schema::hasTable('clinics')) {
            Schema::create('clinics', function (Blueprint $table) {
                $table->id();
                $table->foreignId('doctor_id')->constrained('doctors')->cascadeOnDelete();
                $table->string('name');
                $table->text('address');
                $table->string('city');
                $table->string('state');
                $table->string('postal_code');
                $table->string('phone');
                $table->string('email')->nullable();
                $table->string('website')->nullable();
                $table->string('clinic_image')->nullable();
                $table->string('registration_number')->nullable();
                $table->string('license_number')->nullable();
                $table->boolean('is_verified')->default(false);
                $table->boolean('is_active')->default(true);
                $table->foreignId('country_id')->constrained('countries')->cascadeOnDelete();
                $table->foreignId('zone_id')->nullable()->constrained('zones')->cascadeOnDelete();
                
                // Opening hours for each day
                $table->time('opening_hours_monday_start')->nullable();
                $table->time('opening_hours_monday_end')->nullable();
                $table->time('opening_hours_tuesday_start')->nullable();
                $table->time('opening_hours_tuesday_end')->nullable();
                $table->time('opening_hours_wednesday_start')->nullable();
                $table->time('opening_hours_wednesday_end')->nullable();
                $table->time('opening_hours_thursday_start')->nullable();
                $table->time('opening_hours_thursday_end')->nullable();
                $table->time('opening_hours_friday_start')->nullable();
                $table->time('opening_hours_friday_end')->nullable();
                $table->time('opening_hours_saturday_start')->nullable();
                $table->time('opening_hours_saturday_end')->nullable();
                $table->time('opening_hours_sunday_start')->nullable();
                $table->time('opening_hours_sunday_end')->nullable();
                
                $table->softDeletes();
                $table->timestamps();
                $table->index(['doctor_id', 'is_active']);
                $table->index(['country_id', 'zone_id']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clinics');
    }
};
