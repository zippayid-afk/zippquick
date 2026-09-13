<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Adds payment gateway configuration support using the existing Settings table.
     * Cashfree payment gateway credentials are stored as individual settings rows:
     *   - cashfree_status: 0 or 1 (enabled/disabled)
     *   - cashfree_mode: 'test' or 'live'
     *   - cashfree_app_id: App ID from Cashfree dashboard
     *   - cashfree_secret_key: Secret key from Cashfree dashboard
     *   - cashfree_title: Display title for the payment gateway
     *   - cashfree_logo: Path to the payment gateway logo file
     */
    public function up(): void
    {
        // Initialize default payment gateway settings if they don't exist
        $defaultSettings = [
            'cashfree_status' => 0,
            'cashfree_mode' => 'test',
            'cashfree_app_id' => '',
            'cashfree_secret_key' => '',
            'cashfree_title' => 'Cashfree Payment',
            'cashfree_logo' => '',
        ];

        foreach ($defaultSettings as $key => $value) {
            // Only insert if the setting doesn't already exist
            $exists = DB::table('settings')->where('variable', $key)->exists();
            if (!$exists) {
                DB::table('settings')->insert([
                    'variable' => $key,
                    'value' => $value,
                ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove payment gateway settings
        $settingsToRemove = [
            'cashfree_status',
            'cashfree_mode',
            'cashfree_app_id',
            'cashfree_secret_key',
            'cashfree_title',
            'cashfree_logo',
        ];

        DB::table('settings')->whereIn('variable', $settingsToRemove)->delete();
    }
};
