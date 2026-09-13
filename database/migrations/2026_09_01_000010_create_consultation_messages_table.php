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
        if (!Schema::hasTable('consultation_messages')) {
            Schema::create('consultation_messages', function (Blueprint $table) {
                $table->id();
                $table->foreignId('appointment_id')->constrained('appointments')->cascadeOnDelete();
                $table->unsignedBigInteger('sender_id');
                $table->string('sender_type');
                $table->longText('message');
                $table->string('attachment')->nullable();
                $table->string('attachment_type')->nullable();
                $table->boolean('is_read')->default(false);
                $table->dateTime('read_at')->nullable();
                $table->softDeletes();
                $table->timestamps();
                $table->index(['appointment_id', 'is_read']);
                $table->index('sender_id');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('consultation_messages');
    }
};
