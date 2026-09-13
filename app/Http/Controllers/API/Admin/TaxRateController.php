<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Models\TaxRate;
use App\Helpers\CommonHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class TaxRateController extends Controller
{
    /**
     * Get all tax rates with filters
     */
    public function index(Request $request)
    {
        try {
            $query = TaxRate::query();

            // Filter by active status
            if ($request->has('is_active')) {
                $query->where('is_active', (bool) $request->input('is_active'));
            }

            // Filter by category
            if ($request->has('category') && $request->input('category')) {
                $query->where('category', $request->input('category'));
            }

            // Search by name or hsn_code
            if ($request->has('search') && $request->input('search')) {
                $search = '%' . $request->input('search') . '%';
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', $search)
                      ->orWhere('hsn_code', 'like', $search);
                });
            }

            // Pagination
            $page = (int) $request->input('page', 1);
            $limit = (int) $request->input('limit', 15);
            $offset = ($page - 1) * $limit;

            $total = $query->count();
            $taxRates = $query->orderBy('rate')->offset($offset)->limit($limit)->get();

            return CommonHelper::responseSuccessWithData('Tax rates retrieved successfully', [
                'data' => $taxRates,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => $total,
                    'pages' => ceil($total / $limit),
                ],
            ]);
        } catch (\Exception $e) {
            return CommonHelper::responseError($e->getMessage());
        }
    }

    /**
     * Get single tax rate
     */
    public function show($id)
    {
        try {
            $taxRate = TaxRate::findOrFail($id);
            return CommonHelper::responseSuccessWithData('Tax rate retrieved successfully', $taxRate);
        } catch (\Exception $e) {
            return CommonHelper::responseError('Tax rate not found');
        }
    }

    /**
     * Create new tax rate
     */
    public function store(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|unique:tax_rates,name',
                'rate' => 'required|numeric|min:0|max:100',
                'category' => 'required|in:standard,reduced,zero,exempt,special',
                'description' => 'nullable|string',
                'hsn_code' => 'nullable|string|max:8',
                'is_active' => 'boolean',
                'is_default' => 'boolean',
            ]);

            if ($validator->fails()) {
                return CommonHelper::responseError($validator->errors()->first());
            }

            // If setting as default, unset other defaults
            if ($request->input('is_default')) {
                TaxRate::where('is_default', true)->update(['is_default' => false]);
            }

            $rate = (float) $request->input('rate');
            $cgstRate = $rate / 2;
            $sgstRate = $rate / 2;

            $taxRate = TaxRate::create([
                'name' => $request->input('name'),
                'description' => $request->input('description'),
                'rate' => $rate,
                'cgst_rate' => $cgstRate,
                'sgst_rate' => $sgstRate,
                'igst_rate' => $rate,
                'category' => $request->input('category'),
                'hsn_code' => $request->input('hsn_code'),
                'is_active' => (bool) $request->input('is_active', true),
                'is_default' => (bool) $request->input('is_default', false),
            ]);

            return CommonHelper::responseSuccessWithData('Tax rate created successfully', $taxRate);
        } catch (\Exception $e) {
            return CommonHelper::responseError($e->getMessage());
        }
    }

    /**
     * Update tax rate
     */
    public function update(Request $request, $id)
    {
        try {
            $taxRate = TaxRate::findOrFail($id);

            $validator = Validator::make($request->all(), [
                'name' => 'required|string|unique:tax_rates,name,' . $id,
                'rate' => 'required|numeric|min:0|max:100',
                'category' => 'required|in:standard,reduced,zero,exempt,special',
                'description' => 'nullable|string',
                'hsn_code' => 'nullable|string|max:8',
                'is_active' => 'boolean',
                'is_default' => 'boolean',
            ]);

            if ($validator->fails()) {
                return CommonHelper::responseError($validator->errors()->first());
            }

            // If setting as default, unset other defaults
            if ($request->input('is_default') && !$taxRate->is_default) {
                TaxRate::where('is_default', true)->update(['is_default' => false]);
            }

            $rate = (float) $request->input('rate');
            $cgstRate = $rate / 2;
            $sgstRate = $rate / 2;

            $taxRate->update([
                'name' => $request->input('name'),
                'description' => $request->input('description'),
                'rate' => $rate,
                'cgst_rate' => $cgstRate,
                'sgst_rate' => $sgstRate,
                'igst_rate' => $rate,
                'category' => $request->input('category'),
                'hsn_code' => $request->input('hsn_code'),
                'is_active' => (bool) $request->input('is_active', true),
                'is_default' => (bool) $request->input('is_default', false),
            ]);

            return CommonHelper::responseSuccessWithData('Tax rate updated successfully', $taxRate);
        } catch (\Exception $e) {
            return CommonHelper::responseError($e->getMessage());
        }
    }

    /**
     * Delete tax rate
     */
    public function destroy($id)
    {
        try {
            $taxRate = TaxRate::findOrFail($id);

            // Prevent deletion of default rate
            if ($taxRate->is_default) {
                return CommonHelper::responseError('Cannot delete the default tax rate');
            }

            $taxRate->delete();
            return CommonHelper::responseSuccess('Tax rate deleted successfully');
        } catch (\Exception $e) {
            return CommonHelper::responseError($e->getMessage());
        }
    }

    /**
     * Bulk action (activate/deactivate/delete)
     */
    public function bulkAction(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'action' => 'required|in:activate,deactivate,delete',
                'ids' => 'required|array|min:1',
                'ids.*' => 'integer|exists:tax_rates,id',
            ]);

            if ($validator->fails()) {
                return CommonHelper::responseError($validator->errors()->first());
            }

            $ids = $request->input('ids');
            $action = $request->input('action');

            switch ($action) {
                case 'activate':
                    TaxRate::whereIn('id', $ids)->update(['is_active' => true]);
                    $message = 'Tax rates activated successfully';
                    break;

                case 'deactivate':
                    TaxRate::whereIn('id', $ids)->update(['is_active' => false]);
                    $message = 'Tax rates deactivated successfully';
                    break;

                case 'delete':
                    // Check if any are default
                    if (TaxRate::whereIn('id', $ids)->where('is_default', true)->exists()) {
                        return CommonHelper::responseError('Cannot delete default tax rate');
                    }
                    TaxRate::whereIn('id', $ids)->delete();
                    $message = 'Tax rates deleted successfully';
                    break;
            }

            return CommonHelper::responseSuccess($message);
        } catch (\Exception $e) {
            return CommonHelper::responseError($e->getMessage());
        }
    }

    /**
     * Get tax rate options (for dropdowns)
     */
    public function options()
    {
        try {
            $taxRates = TaxRate::where('is_active', true)
                ->orderBy('rate')
                ->get(['id', 'name', 'rate', 'category']);

            return CommonHelper::responseSuccessWithData('Tax rates retrieved successfully', $taxRates);
        } catch (\Exception $e) {
            return CommonHelper::responseError($e->getMessage());
        }
    }

    /**
     * Get Indian GST rates reference
     */
    public function reference()
    {
        $rates = [
            [
                'rate' => 0,
                'category' => 'zero',
                'description' => 'Essential items (milk, bread, vegetables, etc.)',
                'examples' => 'Fresh produce, unprepared food',
            ],
            [
                'rate' => 5,
                'category' => 'reduced',
                'description' => 'Basic necessities and medicines',
                'examples' => 'Edible oil, sugar, tea, medicines, spices',
            ],
            [
                'rate' => 12,
                'category' => 'standard',
                'description' => 'Processed foods and electronics',
                'examples' => 'Packaged food, computers, phone parts',
            ],
            [
                'rate' => 18,
                'category' => 'standard',
                'description' => 'Standard rate for most goods',
                'examples' => 'Clothing, cosmetics, biscuits, soap',
            ],
            [
                'rate' => 28,
                'category' => 'special',
                'description' => 'Luxury items',
                'examples' => 'Cars, air conditioners, cigarettes',
            ],
        ];

        return CommonHelper::responseSuccessWithData('GST rate reference', $rates);
    }
}
