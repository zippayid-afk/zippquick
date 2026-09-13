<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Add GST fields to products table
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // HSN/SAC code
            $table->string('hsn_code', 20)->nullable()->after('manufacturer')->comment('HSN/SAC code for tax classification');
            
            // GST rate
            $table->decimal('gst_rate', 5, 2)->default(18.00)->after('hsn_code')->comment('GST rate percentage (0, 5, 12, 18, 28)');
            
            // GST inclusive flag
            $table->boolean('gst_inclusive')->default(false)->after('gst_rate')->comment('True if price includes GST, False if GST is added on top');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn([
                'hsn_code',
                'gst_rate',
                'gst_inclusive',
            ]);
        });
    }
};
