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
     * Adds Cloudinary cloud storage configuration support using the existing Settings table.
     * Cloudinary storage credentials are stored as individual settings rows:
     *   - cloudinary_cloud_name: Unique Cloudinary cloud identifier
     *   - cloudinary_api_key: API key from Cloudinary dashboard
     *   - cloudinary_api_secret: API secret from Cloudinary dashboard
     * 
     * Supported formats:
     *   - Images: PNG, JPEG, JPG, WEBP, GIF, SVG, BMP
     *   - Videos: MP4, WEBM, MOV, AVI
     */
    public function up(): void
    {
        // Initialize default Cloudinary storage settings if they don't exist
        $defaultSettings = [
            'cloudinary_cloud_name' => '',
            'cloudinary_api_key' => '',
            'cloudinary_api_secret' => '',
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
        // Remove Cloudinary storage settings
        $settingsToRemove = [
            'cloudinary_cloud_name',
            'cloudinary_api_key',
            'cloudinary_api_secret',
        ];

        DB::table('settings')->whereIn('variable', $settingsToRemove)->delete();
    }
};
