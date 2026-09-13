<?php

namespace App\Http\Controllers\API;

use App\Services\LanguageService;
use App\Helpers\CommonHelper;
use App\Helpers\CloudinaryHelper;
use App\Http\Controllers\Controller;
use App\Models\Brand;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class BrandsApiController extends Controller
{

    protected $languageService;

    public function __construct(LanguageService $languageService)
    {
        $this->languageService = $languageService;
    }

    public function list(Request $request)
    {
        if ($request->filled('id')) {
            $brand = Brand::withAllTranslations()
                ->where('id', $request->id)
                ->first();

            return CommonHelper::responseWithData($brand);
        }

        $limit = $request->input('per_page', 10);
        $page = max((int) $request->input('page', 1), 1);
        $offset = ($page - 1) * $limit;
        $filter = $request->input('filter', '');
        $status = $request->input('status');

        $query = Brand::withAllTranslations()->orderBy('id', 'DESC');

        if ($status !== null && $status !== '') {
            $query->where('status', (int) $status);
        }

        if ($filter) {
            $query->where(function ($q) use ($filter) {
                $q->where('id', 'like', "%{$filter}%")
                  ->orWhere('name', 'like', "%{$filter}%");

                $lowerFilter = strtolower($filter);
                if (str_contains($lowerFilter, 'deac') || str_contains($lowerFilter, 'inac') || $filter === '0') {
                    $q->orWhere('status', 0);
                } elseif (str_contains($lowerFilter, 'act') || $filter === '1') {
                    $q->orWhere('status', 1);
                }
            });
        }

        $total = $query->count();
        $brands = $query->skip($offset)->take($limit)->get();

        return CommonHelper::responseWithData($brands, $total);
    }

    public function save(Request $request)
    {
        $defaultLanguage = $this->languageService->getDefaultLanguage();
        $isDefaultLang = $request->language_id == $defaultLanguage->id;

        $validator = Validator::make($request->all(), [
            'name' => 'required|string',
            'language_id' => 'required|exists:languages,id',
            'image' => 'nullable|image',
        ]);

        if ($validator->fails()) {
            return CommonHelper::responseError($validator->errors()->first());
        }

        if (!$isDefaultLang) {
            return CommonHelper::responseError('default_language_required');
        }

        $brand = new Brand();
        $brand->name = $request->name;
        $brand->status = 1;

        if ($request->hasFile('image')) {
            try {
                $brand->image = CloudinaryHelper::uploadImage($request->file('image'), 'brands');
            } catch (\Exception $e) {
                return CommonHelper::responseError('brand_image_upload_failed');
            }
        }
        $brand->save();

        $brand->saveTranslation($request->language_id, [
            'name' => $request->name,
        ]);
        return CommonHelper::responseWithData([
            'id' => $brand->id,
            'message' => __('brand_saved_successfully'),
        ]);
    }

    public function update(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required|exists:brands,id',
            'name' => 'required|string',
            'language_id' => 'required|exists:languages,id',
            'image' => 'nullable|image',
        ]);

        if ($validator->fails()) {
            return CommonHelper::responseError($validator->errors()->first());
        }

        $brand = Brand::find($request->id);
        if (!$brand) {
            return CommonHelper::responseError('brand_not_found');
        }

        $defaultLanguage = $this->languageService->getDefaultLanguage();
        $isDefaultLang = $request->language_id == $defaultLanguage->id;

        /* UPDATE MAIN TABLE ONLY FOR DEFAULT LANGUAGE */
        if ($isDefaultLang) {
            $brand->name = $request->name;
            $brand->status = $request->status ?? $brand->status;

            if ($request->hasFile('image')) {
                try {
                    $newImageUrl = CloudinaryHelper::uploadImage($request->file('image'), 'brands');
                    // Delete old image from Cloudinary if exists
                    if (!empty($brand->image)) {
                        $publicId = $this->extractPublicIdFromUrl($brand->image);
                        if (!empty($publicId)) {
                            CloudinaryHelper::delete($publicId);
                        }
                    }
                    $brand->image = $newImageUrl;
                } catch (\Exception $e) {
                    return CommonHelper::responseError('brand_image_upload_failed');
                }
            }

            $brand->save();
        }

        /* SAVE / UPDATE TRANSLATION */
        $brand->saveTranslation($request->language_id, [
            'name' => $request->name,
        ]);

        return CommonHelper::responseSuccess('brand_updated_successfully');
    }

    public function delete(Request $request)
    {
        $brand = Brand::find($request->id);

        if (!$brand) {
            return CommonHelper::responseError('brand_not_found');
        }

        // Delete image from Cloudinary if exists
        if (!empty($brand->image)) {
            $publicId = $this->extractPublicIdFromUrl($brand->image);
            if (!empty($publicId)) {
                CloudinaryHelper::delete($publicId);
            }
        }

        $brand->delete();

        return CommonHelper::responseSuccess('brand_deleted_successfully');
    }

    public function getBrands(Request $request)
    {
        $limit = $request->get('limit');
        $offset = $request->get('offset');

        $contentLanguage = $request->header('Content-Language');
        $useContentLanguage = $contentLanguage !== null && trim((string) $contentLanguage) !== '';

        $query = $useContentLanguage
            ? Brand::where('status', 1)->orderBy('id', 'ASC')
            : Brand::withAllTranslations()->where('status', 1)->orderBy('id', 'ASC');

        $total = $query->count();

        if ($limit > 0) {
            $query->skip($offset)->take($limit);
        }

        return CommonHelper::responseWithData($query->get(), $total);
    }

    private function extractPublicIdFromUrl(string $url): string
    {
        // Extract the path after /upload/v{version}/
        if (preg_match('/\/upload\/(?:v\d+\/)?(.+?)(?:\.\w+)?$/', $url, $matches)) {
            return $matches[1];
        }
        return '';
    }
}
