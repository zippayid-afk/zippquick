<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Add GST-related fields to orders table for Indian GST compliance
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // GST breakdown fields
            $table->decimal('cgst_amount', 10, 2)->default(0)->after('tax_amount')->comment('Central GST amount');
            $table->decimal('sgst_amount', 10, 2)->default(0)->after('cgst_amount')->comment('State GST amount');
            $table->decimal('igst_amount', 10, 2)->default(0)->after('sgst_amount')->comment('Integrated GST amount');
            
            // Transaction type flag
            $table->boolean('is_intra_state')->default(true)->after('igst_amount')->comment('True if intra-state transaction (CGST+SGST), False if inter-state (IGST)');
            
            // State information snapshot (for historical accuracy)
            $table->string('company_state', 100)->nullable()->after('is_intra_state')->comment('Company state at order time');
            $table->string('customer_state', 100)->nullable()->after('company_state')->comment('Customer state at order time');
            
            // Company GST details snapshot
            $table->string('company_gstin', 15)->nullable()->after('customer_state')->comment('Company GSTIN at order time');
            $table->string('company_pan', 10)->nullable()->after('company_gstin')->comment('Company PAN at order time');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'cgst_amount',
                'sgst_amount',
                'igst_amount',
                'is_intra_state',
                'company_state',
                'customer_state',
                'company_gstin',
                'company_pan',
            ]);
        });
    }
};
