<?php

namespace App\Http\Controllers\API;

use App\Services\LanguageService;
use App\Helpers\CommonHelper;
use App\Http\Controllers\Controller;
use App\Models\Tax;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class TaxesApiController extends Controller
{
    protected $languageService;

    public function __construct(LanguageService $languageService)
    {
        $this->languageService = $languageService;
    }

    public function getTaxes(Request $request)
    {
        $useContentLanguage = $request->header('Content-Language') !== null
            && trim((string) $request->header('Content-Language')) !== '';

        if ($request->filled('id')) {
            $tax = $useContentLanguage
                ? Tax::query()->where('id', $request->id)->first()
                : Tax::with('translations')->where('id', $request->id)->first();

            return CommonHelper::responseWithData($tax);
        }

        $limit  = (int) $request->get('limit', 10);
        $offset = (int) $request->get('offset', 0);

        $query = $useContentLanguage
            ? Tax::query()->orderBy('id', 'ASC')
            : Tax::with('translations')->orderBy('id', 'ASC');

        // Optional status filter — the product form asks for active taxes only,
        // while the admin tax list omits it to manage every tax.
        if ($request->filled('status')) {
            $query->where('status', (int) $request->input('status'));
        }

        $total = $query->count();

        if ($limit > 0) {
            $query->skip($offset)->take($limit);
        }

        $taxes = $query->get();

        return CommonHelper::responseWithData($taxes, $total);
    }

    public function index()
    {
        $taxes = Tax::with('translations')->orderBy('id', 'DESC')->get();

        return CommonHelper::responseWithData($taxes);
    }
    public function save(Request $request)
    {
        try {
            $defaultLanguage = $this->languageService->getDefaultLanguage();
            if (!$defaultLanguage) {
                return CommonHelper::responseError('default_language_not_found');
            }
            // Loose comparison so string "5" from FormData matches int 5 – same as BrandsApiController
            $isDefaultLanguage = $request->language_id == $defaultLanguage->id;

            // Default language: title and percentage required. Non-default: optional (translation only).
            $rules = [
                'language_id' => 'required|exists:languages,id',
            ];
            if ($isDefaultLanguage) {
                $rules['title']      = 'required|string';
                $rules['percentage'] = 'required|numeric|min:0.1|max:100';
            } else {
                $rules['title']      = 'nullable|string';
                $rules['percentage'] = 'nullable|numeric|min:0|max:100';
            }

            $validator = Validator::make($request->all(), $rules);

            if ($validator->fails()) {
                return CommonHelper::responseError($validator->errors()->first());
            }

            if (!$isDefaultLanguage && !$request->filled('id')) {
                return CommonHelper::responseError('default_language_required');
            }

            if (!$request->filled('id')) {
                $tax = new Tax();
                $tax->title      = $request->title ?? '';
                $tax->percentage = $request->percentage ?? 0;
                $tax->status     = 1;
                $tax->save();
            } else {
                $tax = Tax::find($request->id);
                if (!$tax) {
                    return CommonHelper::responseError('tax_not_found');
                }
                // Update main tax record when saving in default language (so title/percentage persist)
                if ($isDefaultLanguage) {
                    $tax->title      = $request->title;
                    $tax->percentage = $request->percentage;
                    $tax->save();
                }
            }

            $tax->saveTranslation($request->language_id, [
                'title' => $request->input('title', ''),
            ]);

            return CommonHelper::responseSuccessWithData('tax_saved_successfully', $tax->fresh());
        } catch (\Exception $e) {
            return CommonHelper::responseError($e->getMessage());
        }
    }

    public function update(Request $request)
    {
        try {
            $defaultLanguage = $this->languageService->getDefaultLanguage();
            $isDefaultLanguage = $defaultLanguage && $request->language_id == $defaultLanguage->id;

            $rules = [
                'id'          => 'required|exists:taxes,id',
                'language_id' => 'required|exists:languages,id',
            ];
            $rules['title'] = $isDefaultLanguage ? 'required|string' : 'nullable|string';

            $validator = Validator::make($request->all(), $rules);

            if ($validator->fails()) {
                return CommonHelper::responseError($validator->errors()->first());
            }

            $tax = Tax::find($request->id);
            if (!$tax) {
                return CommonHelper::responseError('tax_not_found');
            }

            if ($isDefaultLanguage) {
                if ($request->has('percentage')) {
                    $tax->percentage = $request->percentage;
                }

                if ($request->has('status')) {
                    $tax->status = $request->status;
                }

                $tax->title = $request->title ?? '';
                $tax->save();
            }

            $tax->saveTranslation($request->language_id, [
                'title' => $request->input('title', ''),
            ]);

            return CommonHelper::responseSuccess('tax_updated_successfully');
        } catch (\Exception $e) {
            return CommonHelper::responseError($e->getMessage());
        }
    }

    public function delete(Request $request)
    {
        try {
            $tax = Tax::find($request->id);

            if (!$tax) {
                return CommonHelper::responseError('tax_not_found');
            }

            $tax->delete();
            return CommonHelper::responseSuccess('tax_deleted_successfully');
        } catch (\Exception $e) {
            return CommonHelper::responseError($e->getMessage());
        }
    }
}
