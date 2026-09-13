<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Centralized GST Calculation Service
 * 
 * Handles all GST calculations for:
 * - Product pricing
 * - Cart calculations
 * - Checkout calculations
 * - Order creation
 * - Invoice generation
 * 
 * Supports:
 * - GST Inclusive pricing
 * - GST Exclusive pricing
 * - Intra-State (CGST + SGST)
 * - Inter-State (IGST)
 * - Multiple GST rates per product
 * - Quantity-aware calculations
 * - Discount-aware calculations
 */
class GSTService
{
    /**
     * Standard Indian GST rates
     */
    const GST_RATES = [0, 5, 12, 18, 28];

    /**
     * Get company/business GST configuration
     */
    public function getCompanyGSTConfig(): array
    {
        return Cache::remember('company_gst_config', 3600, function () {
            $settings = Setting::whereIn('variable', [
                'company_state',
                'company_gstin',
                'company_pan',
                'company_address_line1',
                'company_address_line2',
                'company_city',
                'company_pincode',
                'gst_enabled',
            ])->pluck('value', 'variable')->toArray();

            return [
                'state' => $settings['company_state'] ?? 'Maharashtra',
                'gstin' => $settings['company_gstin'] ?? '',
                'pan' => $settings['company_pan'] ?? '',
                'address_line1' => $settings['company_address_line1'] ?? '',
                'address_line2' => $settings['company_address_line2'] ?? '',
                'city' => $settings['company_city'] ?? '',
                'pincode' => $settings['company_pincode'] ?? '',
                'enabled' => (bool) ($settings['gst_enabled'] ?? true),
            ];
        });
    }

    /**
     * Determine if transaction is intra-state or inter-state
     * 
     * @param string $companyState Seller/Business state
     * @param string $customerState Customer's state
     * @return bool True if intra-state (same state), False if inter-state
     */
    public function isIntraState(string $companyState, string $customerState): bool
    {
        // Normalize state names for comparison
        $companyState = trim(strtolower($companyState));
        $customerState = trim(strtolower($customerState));

        return $companyState === $customerState;
    }

    /**
     * Calculate GST for a single item
     * 
     * @param float $price Base price (per unit)
     * @param float $gstRate GST rate percentage (0, 5, 12, 18, 28)
     * @param bool $gstInclusive True if price includes GST, False if GST to be added
     * @param int $quantity Quantity of items
     * @param string $companyState Seller state
     * @param string $customerState Customer state
     * @param float $discount Discount amount (optional)
     * @return array Detailed GST breakdown
     */
    public function calculateItemGST(
        float $price,
        float $gstRate,
        bool $gstInclusive,
        int $quantity = 1,
        string $companyState = '',
        string $customerState = '',
        float $discount = 0
    ): array {
        // Validate inputs
        $price = max(0, (float) $price);
        $gstRate = max(0, (float) $gstRate);
        $quantity = max(1, (int) $quantity);
        $discount = max(0, (float) $discount);

        // Get company state if not provided
        if (empty($companyState)) {
            $config = $this->getCompanyGSTConfig();
            $companyState = $config['state'];
        }

        // Determine transaction type
        $isIntraState = !empty($customerState) ? $this->isIntraState($companyState, $customerState) : true;

        // Calculate base taxable amount
        if ($gstInclusive) {
            // Price includes GST - extract taxable amount
            // Formula: Taxable = Price / (1 + GST_Rate/100)
            $taxablePerUnit = $gstRate > 0 ? $price / (1 + ($gstRate / 100)) : $price;
        } else {
            // Price excludes GST - price is the taxable amount
            $taxablePerUnit = $price;
        }

        // Apply discount before calculating GST
        $totalTaxableBeforeDiscount = $taxablePerUnit * $quantity;
        $taxableAmount = max(0, $totalTaxableBeforeDiscount - $discount);

        // Calculate total GST
        $totalGST = ($taxableAmount * $gstRate) / 100;

        // Split GST based on transaction type
        if ($isIntraState) {
            // Intra-State: CGST + SGST (50% each)
            $cgstRate = $gstRate / 2;
            $sgstRate = $gstRate / 2;
            $cgstAmount = $totalGST / 2;
            $sgstAmount = $totalGST / 2;
            $igstRate = 0;
            $igstAmount = 0;
        } else {
            // Inter-State: IGST (100%)
            $cgstRate = 0;
            $sgstRate = 0;
            $cgstAmount = 0;
            $sgstAmount = 0;
            $igstRate = $gstRate;
            $igstAmount = $totalGST;
        }

        // Calculate final amount
        if ($gstInclusive) {
            // For inclusive pricing, final amount = (price * quantity) - discount
            $finalAmount = max(0, ($price * $quantity) - $discount);
        } else {
            // For exclusive pricing, add GST on top
            $finalAmount = $taxableAmount + $totalGST;
        }

        return [
            'price_per_unit' => round($price, 2),
            'quantity' => $quantity,
            'price_type' => $gstInclusive ? 'inclusive' : 'exclusive',
            'gst_rate' => round($gstRate, 2),
            'discount' => round($discount, 2),
            
            // Taxable amount (after discount)
            'taxable_amount' => round($taxableAmount, 2),
            
            // GST breakdown
            'cgst_rate' => round($cgstRate, 2),
            'cgst_amount' => round($cgstAmount, 2),
            'sgst_rate' => round($sgstRate, 2),
            'sgst_amount' => round($sgstAmount, 2),
            'igst_rate' => round($igstRate, 2),
            'igst_amount' => round($igstAmount, 2),
            
            // Total GST
            'total_gst' => round($totalGST, 2),
            
            // Final amount customer pays
            'final_amount' => round($finalAmount, 2),
            
            // Transaction type
            'tax_type' => $isIntraState ? 'intra_state' : 'inter_state',
            'is_intra_state' => $isIntraState,
            
            // State information
            'company_state' => $companyState,
            'customer_state' => $customerState,
        ];
    }

    /**
     * Calculate GST for multiple items (cart/order)
     * 
     * @param array $items Array of items, each with: price, gst_rate, gst_inclusive, quantity, discount
     * @param string $customerState Customer's state
     * @return array Aggregated GST breakdown
     */
    public function calculateCartGST(array $items, string $customerState = ''): array
    {
        $config = $this->getCompanyGSTConfig();
        $companyState = $config['state'];

        $totalTaxable = 0;
        $totalCGST = 0;
        $totalSGST = 0;
        $totalIGST = 0;
        $totalGST = 0;
        $grandTotal = 0;
        $itemBreakdowns = [];

        foreach ($items as $item) {
            $itemCalc = $this->calculateItemGST(
                $item['price'] ?? 0,
                $item['gst_rate'] ?? 0,
                $item['gst_inclusive'] ?? false,
                $item['quantity'] ?? 1,
                $companyState,
                $customerState,
                $item['discount'] ?? 0
            );

            $totalTaxable += $itemCalc['taxable_amount'];
            $totalCGST += $itemCalc['cgst_amount'];
            $totalSGST += $itemCalc['sgst_amount'];
            $totalIGST += $itemCalc['igst_amount'];
            $totalGST += $itemCalc['total_gst'];
            $grandTotal += $itemCalc['final_amount'];

            $itemBreakdowns[] = $itemCalc;
        }

        $isIntraState = !empty($customerState) ? $this->isIntraState($companyState, $customerState) : true;

        return [
            'items' => $itemBreakdowns,
            'summary' => [
                'total_taxable_amount' => round($totalTaxable, 2),
                'total_cgst' => round($totalCGST, 2),
                'total_sgst' => round($totalSGST, 2),
                'total_igst' => round($totalIGST, 2),
                'total_gst' => round($totalGST, 2),
                'grand_total' => round($grandTotal, 2),
                'tax_type' => $isIntraState ? 'intra_state' : 'inter_state',
                'is_intra_state' => $isIntraState,
                'company_state' => $companyState,
                'customer_state' => $customerState,
            ],
            'company_config' => $config,
        ];
    }

    /**
     * Get GST display text for UI
     * 
     * @param float $gstRate GST rate
     * @param bool $gstInclusive Whether GST is inclusive
     * @return string Display text
     */
    public function getGSTDisplayText(float $gstRate, bool $gstInclusive): string
    {
        if ($gstRate == 0) {
            return 'No GST';
        }

        $type = $gstInclusive ? 'Inclusive' : 'Exclusive';
        return number_format($gstRate, 0) . '% GST ' . $type;
    }

    /**
     * Validate GST rate
     * 
     * @param float $rate GST rate to validate
     * @return bool True if valid
     */
    public function isValidGSTRate(float $rate): bool
    {
        return in_array($rate, self::GST_RATES) || ($rate >= 0 && $rate <= 100);
    }

    /**
     * Get price breakdown for display (for product details page)
     * 
     * @param float $price Price
     * @param float $gstRate GST rate
     * @param bool $gstInclusive Is GST inclusive
     * @param string $customerState Customer state (optional)
     * @return array Price breakdown for display
     */
    public function getPriceBreakdown(
        float $price,
        float $gstRate,
        bool $gstInclusive,
        string $customerState = ''
    ): array {
        $calc = $this->calculateItemGST($price, $gstRate, $gstInclusive, 1, '', $customerState);

        return [
            'display_price' => round($price, 2),
            'taxable_value' => $calc['taxable_amount'],
            'gst_amount' => $calc['total_gst'],
            'final_price' => $calc['final_amount'],
            'gst_text' => $this->getGSTDisplayText($gstRate, $gstInclusive),
            'breakdown' => [
                'cgst' => $calc['cgst_amount'],
                'sgst' => $calc['sgst_amount'],
                'igst' => $calc['igst_amount'],
            ],
            'is_intra_state' => $calc['is_intra_state'],
        ];
    }

    /**
     * Clear GST configuration cache
     */
    public function clearCache(): void
    {
        Cache::forget('company_gst_config');
    }

    /**
     * Log GST calculation for debugging
     */
    private function logGSTCalculation(string $context, array $data): void
    {
        if (config('app.debug')) {
            Log::channel('daily')->info("GST Calculation [{$context}]", $data);
        }
    }
}
