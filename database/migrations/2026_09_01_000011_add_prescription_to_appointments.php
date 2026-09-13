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
        if (Schema::hasTable('appointments') && !Schema::hasColumn('appointments', 'prescription_id')) {
            Schema::table('appointments', function (Blueprint $table) {
                $table->unsignedBigInteger('prescription_id')->nullable()->after('cancellation_reason');
                $table->foreign('prescription_id')->references('id')->on('prescriptions')->onDelete('set null');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('appointments') && Schema::hasColumn('appointments', 'prescription_id')) {
            Schema::table('appointments', function (Blueprint $table) {
                $table->dropForeign(['prescription_id']);
                $table->dropColumn('prescription_id');
            });
        }
    }
};
