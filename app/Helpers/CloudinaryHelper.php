<?php

namespace App\Helpers;

use App\Models\Setting;
use Cloudinary\Cloudinary;
use Cloudinary\Api\Upload\UploadApi;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

/**
 * CloudinaryHelper
 * 
 * Handles all file uploads to Cloudinary for images, videos, and assets.
 * All uploads bypass local storage and go directly to Cloudinary.
 * Uses the official Cloudinary PHP SDK for reliable authentication.
 */
class CloudinaryHelper
{
    private static $cloudinary;

    /**
     * Initialize Cloudinary SDK with credentials from settings
     */
    private static function init()
    {
        if (!self::$cloudinary) {
            $cloudName = Setting::get_value('cloudinary_cloud_name');
            $apiKey = Setting::get_value('cloudinary_api_key');
            $apiSecret = Setting::get_value('cloudinary_api_secret');

            if (empty($cloudName) || empty($apiKey) || empty($apiSecret)) {
                throw new \Exception('Cloudinary credentials are not configured in settings');
            }

            // Initialize Cloudinary with credentials
            self::$cloudinary = new Cloudinary([
                'cloud' => [
                    'cloud_name' => $cloudName,
                    'api_key' => $apiKey,
                    'api_secret' => $apiSecret,
                ],
            ]);
        }
    }

    /**
     * Upload file to Cloudinary
     * 
     * @param UploadedFile $file The file to upload
     * @param string $folder Cloudinary folder path (e.g., 'payment-gateways/cashfree')
     * @param array $options Additional Cloudinary options
     * @return string Cloudinary secure URL of the uploaded file
     * @throws \Exception
     */
    public static function upload(UploadedFile $file, $folder = '', $options = [])
    {
        try {
            self::init();

            // Prepare upload options
            $uploadOptions = $options;
            
            if (!empty($folder)) {
                $uploadOptions['folder'] = $folder;
            }
            
            // Use the uploaded file directly
            $uploadApi = self::$cloudinary->uploadApi();
            $response = $uploadApi->upload($file->getRealPath(), $uploadOptions);

            if (isset($response['secure_url'])) {
                Log::info('File uploaded to Cloudinary: ' . $response['secure_url']);
                return $response['secure_url'];
            }

            throw new \Exception('Cloudinary upload failed: No secure_url in response');
        } catch (\Exception $e) {
            Log::error('Cloudinary upload error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Upload image file to Cloudinary
     * 
     * @param UploadedFile $file
     * @param string $folder
     * @return string Cloudinary URL
     */
    public static function uploadImage(UploadedFile $file, $folder = 'images')
    {
        return self::upload($file, $folder, [
            'resource_type' => 'image',
            'quality' => 'auto',
        ]);
    }

    /**
     * Upload video file to Cloudinary
     * 
     * @param UploadedFile $file
     * @param string $folder
     * @return string Cloudinary URL
     */
    public static function uploadVideo(UploadedFile $file, $folder = 'videos')
    {
        return self::upload($file, $folder, [
            'resource_type' => 'video',
        ]);
    }

    /**
     * Upload asset (logo, icon, etc.) to Cloudinary
     * 
     * @param UploadedFile $file
     * @param string $folder
     * @return string Cloudinary URL
     */
    public static function uploadAsset(UploadedFile $file, $folder = 'assets')
    {
        return self::upload($file, $folder, [
            'resource_type' => 'auto',
        ]);
    }

    /**
     * Delete file from Cloudinary
     * 
     * @param string $publicId Cloudinary public ID
     * @param string $resourceType Resource type: 'image', 'video', 'raw', or 'auto'
     * @return bool
     */
    public static function delete($publicId, $resourceType = 'image')
    {
        try {
            if (empty($publicId)) {
                Log::warning('Cloudinary delete called with empty publicId');
                return false;
            }

            self::init();

            // Use uploadApi()->destroy() instead of adminApi()->deleteResources()
            $uploadApi = self::$cloudinary->uploadApi();
            $response = $uploadApi->destroy($publicId, [
                'resource_type' => $resourceType,
                'invalidate' => true
            ]);

            if (isset($response['result']) && $response['result'] === 'ok') {
                Log::info('File deleted from Cloudinary: ' . $publicId);
                return true;
            }

            // If not found, still return true (already deleted)
            if (isset($response['result']) && $response['result'] === 'not found') {
                Log::info('File not found in Cloudinary (already deleted): ' . $publicId);
                return true;
            }

            Log::warning('Cloudinary delete returned unexpected result: ' . json_encode($response));
            return false;

        } catch (\Exception $e) {
            Log::error('Cloudinary delete error for publicId "' . $publicId . '": ' . $e->getMessage());
            // Return true to not block the database deletion
            return true;
        }
    }

    /**
     * Delete multiple files from Cloudinary
     * 
     * @param array $publicIds Array of Cloudinary public IDs
     * @param string $resourceType Resource type: 'image', 'video', 'raw'
     * @return bool
     */
    public static function deleteMultiple(array $publicIds, $resourceType = 'image')
    {
        $allSuccess = true;
        foreach ($publicIds as $publicId) {
            if (!self::delete($publicId, $resourceType)) {
                $allSuccess = false;
            }
        }
        return $allSuccess;
    }

    /**
     * Check if Cloudinary is configured
     * 
     * @return bool
     */
    public static function isConfigured()
    {
        $cloudName = Setting::get_value('cloudinary_cloud_name');
        $apiKey = Setting::get_value('cloudinary_api_key');
        $apiSecret = Setting::get_value('cloudinary_api_secret');

        return !empty($cloudName) && !empty($apiKey) && !empty($apiSecret);
    }
}

