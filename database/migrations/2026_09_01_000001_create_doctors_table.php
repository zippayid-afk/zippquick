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
        if (!Schema::hasTable('doctors')) {
            Schema::create('doctors', function (Blueprint $table) {
                $table->id();
                $table->foreignId('admin_id')->constrained('admins')->cascadeOnDelete();
                $table->string('first_name');
                $table->string('last_name');
                $table->string('email')->unique();
                $table->string('mobile')->unique();
                $table->string('specialization');
                $table->string('qualification');
                $table->string('license_number')->unique();
                $table->integer('experience_years')->default(0);
                $table->longText('bio')->nullable();
                $table->string('profile_image')->nullable();
                $table->string('license_document')->nullable();
                $table->string('certificate_document')->nullable();
                $table->boolean('is_verified')->default(false);
                $table->boolean('is_approved')->default(false);
                $table->boolean('is_active')->default(true);
                $table->dateTime('approval_date')->nullable();
                $table->text('rejection_reason')->nullable();
                $table->foreignId('country_id')->constrained('countries')->cascadeOnDelete();
                $table->foreignId('zone_id')->nullable()->constrained('zones')->cascadeOnDelete();
                $table->softDeletes();
                $table->timestamps();
                $table->index(['is_approved', 'is_active']);
                $table->index(['country_id', 'zone_id']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('doctors');
    }
};
