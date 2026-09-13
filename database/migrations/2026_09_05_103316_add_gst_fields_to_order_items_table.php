<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Add GST breakdown fields to order_items table
     */
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            // Only add columns that don't exist yet
            if (!Schema::hasColumn('order_items', 'cgst_amount')) {
                $table->decimal('cgst_amount', 10, 2)->default(0)->after('tax_amount')->comment('CGST amount for this item');
            }
            if (!Schema::hasColumn('order_items', 'sgst_amount')) {
                $table->decimal('sgst_amount', 10, 2)->default(0)->after('cgst_amount')->comment('SGST amount for this item');
            }
            if (!Schema::hasColumn('order_items', 'igst_amount')) {
                $table->decimal('igst_amount', 10, 2)->default(0)->after('sgst_amount')->comment('IGST amount for this item');
            }
            if (!Schema::hasColumn('order_items', 'gst_rate')) {
                $table->decimal('gst_rate', 5, 2)->default(0)->after('igst_amount')->comment('GST rate (%) applied to this item');
            }
            // hsn_code already exists, so skip it
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn([
                'cgst_amount',
                'sgst_amount',
                'igst_amount',
                'hsn_code',
                'gst_rate',
            ]);
        });
    }
};
