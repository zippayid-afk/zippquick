<?php

namespace App\Http\Controllers\API;

use App\Helpers\CommonHelper;
use App\Helpers\CloudinaryHelper;
use App\Http\Controllers\Controller;
use App\Models\Media;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
class MediaApiController extends Controller
{

    private function safeFileName(string $original): string
    {
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        $base = strtolower(pathinfo($original, PATHINFO_FILENAME));
        $base = trim(preg_replace('/[^a-z0-9._-]+/', '-', $base), '-.');
        $base = $base !== '' ? $base : 'file';

        return $base . ($ext !== '' ? '.' . $ext : '');
    }

    /** Keep the original (sanitized) name; on collision append _1, _2, ... so nothing is overwritten. */
    private function uniqueFileName(string $directory, string $fileName): string
    {
        $ext = pathinfo($fileName, PATHINFO_EXTENSION);
        $base = pathinfo($fileName, PATHINFO_FILENAME);
        $suffix = $ext !== '' ? '.' . $ext : '';

        $candidate = $fileName;
        $counter = 1;
        while (Storage::disk('public')->exists(rtrim($directory, '/') . '/' . $candidate)) {
            $candidate = $base . '_' . $counter . $suffix;
            $counter++;
        }

        return $candidate;
    }

    /**
     * Extract public ID from Cloudinary URL
     * Example: https://res.cloudinary.com/xxx/image/upload/v1234/media/image.jpg => media/image
     */
    private function extractPublicIdFromUrl(string $url): string
    {
        // Extract the path after /upload/v{version}/
        if (preg_match('/\/upload\/(?:v\d+\/)?(.+?)(?:\.\w+)?$/', $url, $matches)) {
            return $matches[1];
        }
        return '';
    }

    public function index()
    {
        $media = Media::select("media.*", "stores.name as store_name")
            ->leftJoin('stores', 'media.store_id', '=', 'stores.id')
            ->orderBy('id', 'DESC')->get();
        return CommonHelper::responseWithData($media);
    }
    public function save(Request $request)
    {
        if ($request->hasFile('files')) {
            // First, verify Cloudinary is configured
            if (!CloudinaryHelper::isConfigured()) {
                return CommonHelper::responseError('Cloudinary is not configured. Please configure Cloudinary settings at /settings/api');
            }

            $files = $request->file('files');
            // Only images and SVG are allowed — no video or other formats.
            $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
            $allowedMimes = ['image/jpeg', 'image/pjpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml', 'image/svg'];
            foreach ($files as $file) {
                $ext = strtolower($file->getClientOriginalExtension());
                $mime = strtolower((string) $file->getClientMimeType());
                if (!in_array($ext, $allowedExtensions, true) || !in_array($mime, $allowedMimes, true)) {
                    return CommonHelper::responseError('only_image_and_svg_files_are_allowed');
                }
            }
            foreach ($files as $key => $file) {
                try {
                    $fileName = $this->safeFileName($file->getClientOriginalName());
                    $extension = $file->getClientOriginalExtension();
                    $type = $file->getClientMimeType();
                    $size = $file->getSize();
                    $precision = 2;
                    $base = log($size, 1024);
                    $suffixes = array('', 'KB', 'MB', 'GB', 'TB');
                    $covertedSize = round(pow(1024, $base - floor($base)), $precision) . ' ' . $suffixes[floor($base)];
                    
                    // Upload to Cloudinary
                    $cloudinaryUrl = CloudinaryHelper::uploadImage($file, 'media');
                    
                    $media = new Media();
                    $media->name = $fileName;
                    $media->extension = $extension;
                    $media->type = $type;
                    $media->sub_directory = $cloudinaryUrl; // Store Cloudinary URL in sub_directory
                    $media->size = $covertedSize;
                    $media->store_id = 0;
                    $media->save();
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::error('Media upload error: ' . $e->getMessage() . '\n' . $e->getTraceAsString());
                    return CommonHelper::responseError('media_upload_failed_to_cloudinary: ' . $e->getMessage());
                }
            }
            return CommonHelper::responseSuccess('media_image_uploaded_successfully');
        }
    }
    public function delete(Request $request)
    {
        if (isset($request->id)) {
            $media = Media::find($request->id);
            if ($media) {
                // Delete from Cloudinary - sub_directory now contains the URL
                if (!empty($media->sub_directory) && preg_match('~^https?://~', $media->sub_directory)) {
                    $publicId = $this->extractPublicIdFromUrl($media->sub_directory);
                    if (!empty($publicId)) {
                        try {
                            CloudinaryHelper::delete($publicId, 'image');
                        } catch (\Exception $e) {
                            \Illuminate\Support\Facades\Log::warning('Failed to delete from Cloudinary: ' . $e->getMessage());
                            // Continue with local deletion even if Cloudinary delete fails
                        }
                    }
                }
                $media->delete();
                return CommonHelper::responseSuccess('media_file_deleted_successfully');
            } else {
                return CommonHelper::responseSuccess('media_file_already_deleted');
            }
        }
        return CommonHelper::responseError('media_id_not_provided');
    }

    public function multipleDelete(Request $request)
    {
        if (isset($request->ids)) {
            $ids = explode(',', $request->ids);

            $mediaFiles = Media::whereIn('id', $ids)->get();
            foreach ($mediaFiles as $media) {
                // Delete from Cloudinary if URL exists - sub_directory now contains the URL
                if (!empty($media->sub_directory) && preg_match('~^https?://~', $media->sub_directory)) {
                    $publicId = $this->extractPublicIdFromUrl($media->sub_directory);
                    if (!empty($publicId)) {
                        try {
                            CloudinaryHelper::delete($publicId, 'image');
                        } catch (\Exception $e) {
                            \Illuminate\Support\Facades\Log::warning('Failed to delete from Cloudinary: ' . $e->getMessage());
                            // Continue with local deletion even if Cloudinary delete fails
                        }
                    }
                }
                $media->delete();
            }

            return CommonHelper::responseSuccess('selected_all_media_files_deleted_successfully');
        }
        return CommonHelper::responseError('media_ids_not_provided');
    }

    public function editorUpload(Request $request)
    {
        if ($request->hasFile('file')) {
            try {
                $file = $request->file('file');
                
                // Upload to Cloudinary
                $cloudinaryUrl = CloudinaryHelper::uploadImage($file, 'media/editor');
                
                return response()->json([
                    'location' => $cloudinaryUrl
                ]);
            } catch (\Exception $e) {
                return response()->json(['error' => 'Failed to upload file to Cloudinary'], 400);
            }
        }
        return response()->json(['error' => 'No file uploaded'], 400);
    }
}
