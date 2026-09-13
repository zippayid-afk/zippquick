<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Add GST configuration settings
     */
    public function up(): void
    {
        // Insert GST configuration settings
        DB::table('settings')->insertOrIgnore([
            [
                'variable' => 'company_state',
                'value' => 'Maharashtra',
            ],
            [
                'variable' => 'company_gstin',
                'value' => '',
            ],
            [
                'variable' => 'company_pan',
                'value' => '',
            ],
            [
                'variable' => 'company_address_line1',
                'value' => '',
            ],
            [
                'variable' => 'company_address_line2',
                'value' => '',
            ],
            [
                'variable' => 'company_city',
                'value' => '',
            ],
            [
                'variable' => 'company_pincode',
                'value' => '',
            ],
            [
                'variable' => 'gst_enabled',
                'value' => '1',
            ],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('settings')->whereIn('variable', [
            'company_state',
            'company_gstin',
            'company_pan',
            'company_address_line1',
            'company_address_line2',
            'company_city',
            'company_pincode',
            'gst_enabled',
        ])->delete();
    }
};
