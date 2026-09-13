<?php

namespace App\Helpers;

use App\Models\Setting;
use App\Models\Product;

class GSTHelper
{
    /**
     * Calculate GST breakdown for an order
     * 
     * @param object $itemDetails Collection of product details
     * @param array $quantities Array of quantities matching item details
     * @param array $shippingAddress Customer shipping address
     * @param float $couponDiscount Coupon discount amount
     * @return array GST calculation breakdown
     */
    public static function calculateOrderGST($itemDetails, $quantities, $shippingAddress, $couponDiscount = 0)
    {
        // Get company state from settings
        $companyState = Setting::get_value('company_state') ?? 'Maharashtra';
        $customerState = $shippingAddress['state'] ?? 'Maharashtra';
        
        // Determine if intra-state or inter-state
        $isIntraState = strtolower(trim($companyState)) === strtolower(trim($customerState));
        
        // Initialize totals
        $subtotalBeforeDiscount = 0;
        $totalCGST = 0;
        $totalSGST = 0;
        $totalIGST = 0;
        $itemBreakdown = [];
        
        // Calculate subtotal before discount (from existing tax calculation)
        $itemDetails = collect($itemDetails);
        foreach ($itemDetails as $key => $item) {
            $quantity = $quantities[$key] ?? 1;
            $price = (float) ($item->price ?? 0);
            $itemTotal = $price * $quantity;
            $subtotalBeforeDiscount += $itemTotal;
        }
        
        // Apply discount
        $subtotalAfterDiscount = $subtotalBeforeDiscount - $couponDiscount;
        
        // Calculate GST per item with proportional discount
        foreach ($itemDetails as $key => $item) {
            $quantity = $quantities[$key] ?? 1;
            $price = (float) ($item->price ?? 0);
            $gstInclusive = (bool) ($item->gst_inclusive ?? false);
            
            // Calculate item total
            $itemTotal = $price * $quantity;
            
            // Calculate proportional discount for this item
            $itemDiscount = $subtotalBeforeDiscount > 0 
                ? ($itemTotal / $subtotalBeforeDiscount) * $couponDiscount 
                : 0;
            $itemTotalAfterDiscount = $itemTotal - $itemDiscount;
            
            // Get GST rate (use product's rate or tax_percentage or default to 18%)
            $gstRate = (float) ($item->gst_rate ?? $item->tax_percentage ?? 18);
            $hsnCode = $item->hsn_code ?? '';
            
            /**
             * GST Calculation Logic (per official CBIC rules):
             * 
             * GST Inclusive (price includes GST):
             *   taxable_value = price / (1 + rate/100)
             *   gst_amount = price - taxable_value
             * 
             * GST Exclusive (price excludes GST):
             *   taxable_value = price
             *   gst_amount = price * rate / 100
             */
            if ($gstInclusive) {
                // Price includes GST - extract taxable value
                $taxableAmount = $gstRate > 0 ? $itemTotalAfterDiscount / (1 + ($gstRate / 100)) : $itemTotalAfterDiscount;
                $gstAmount = $itemTotalAfterDiscount - $taxableAmount;
            } else {
                // Price excludes GST - add GST on top
                $taxableAmount = $itemTotalAfterDiscount;
                $gstAmount = ($itemTotalAfterDiscount * $gstRate) / 100;
            }
            
            // Split GST based on transaction type
            $cgst = 0;
            $sgst = 0;
            $igst = 0;
            
            if ($isIntraState) {
                // Intra-State: Split 50/50
                $cgst = $gstAmount / 2;
                $sgst = $gstAmount / 2;
                $totalCGST += $cgst;
                $totalSGST += $sgst;
            } else {
                // Inter-State: Full IGST
                $igst = $gstAmount;
                $totalIGST += $igst;
            }
            
            // Calculate final amount based on GST type
            if ($gstInclusive) {
                // For inclusive pricing, final amount = price (customer pays this)
                $finalAmount = $itemTotalAfterDiscount;
            } else {
                // For exclusive pricing, final amount = base + GST
                $finalAmount = $itemTotalAfterDiscount + $gstAmount;
            }
            
            $itemBreakdown[] = [
                'product_id' => $item->product_id ?? $item->id,
                'product_name' => $item->product_name ?? $item->name ?? 'Product',
                'quantity' => $quantity,
                'price' => $price,
                'item_total' => $itemTotal,
                'item_discount' => $itemDiscount,
                'item_total_after_discount' => $itemTotalAfterDiscount,
                'taxable_amount' => round($taxableAmount, 2),
                'gst_rate' => $gstRate,
                'gst_inclusive' => $gstInclusive,
                'gst_amount' => round($gstAmount, 2),
                'cgst' => round($cgst, 2),
                'sgst' => round($sgst, 2),
                'igst' => round($igst, 2),
                'final_amount' => round($finalAmount, 2),
                'hsn_code' => $hsnCode,
            ];
        }
        
        // Calculate total GST
        $totalGST = $totalCGST + $totalSGST + $totalIGST;
        
        return [
            'subtotal_before_discount' => round($subtotalBeforeDiscount, 2),
            'coupon_discount' => round($couponDiscount, 2),
            'subtotal_after_discount' => round($subtotalAfterDiscount, 2),
            'gst' => [
                'total_gst' => round($totalGST, 2),
                'cgst' => round($totalCGST, 2),
                'sgst' => round($totalSGST, 2),
                'igst' => round($totalIGST, 2),
                'is_intra_state' => $isIntraState,
                'company_state' => $companyState,
                'customer_state' => $customerState,
            ],
            'item_breakdown' => $itemBreakdown,
        ];
    }
    
    /**
     * Get company GST configuration
     * 
     * @return array Company GST details
     */
    public static function getCompanyGSTConfig()
    {
        return [
            'company_state' => Setting::get_value('company_state') ?? 'Maharashtra',
            'company_gstin' => Setting::get_value('company_gstin') ?? '',
            'company_pan' => Setting::get_value('company_pan') ?? '',
            'gst_enabled' => Setting::get_value('gst_enabled') ?? '1',
        ];
    }
    
    /**
     * Get Indian states list
     * 
     * @return array
     */
    public static function getIndianStates()
    {
        return [
            'Andhra Pradesh', 'Arunachal Pradesh', 'Assam', 'Bihar', 'Chhattisgarh',
            'Goa', 'Gujarat', 'Haryana', 'Himachal Pradesh', 'Jharkhand',
            'Karnataka', 'Kerala', 'Madhya Pradesh', 'Maharashtra', 'Manipur',
            'Meghalaya', 'Mizoram', 'Nagaland', 'Odisha', 'Punjab',
            'Rajasthan', 'Sikkim', 'Tamil Nadu', 'Telangana', 'Tripura',
            'Uttar Pradesh', 'Uttarakhand', 'West Bengal', 'Delhi', 'Puducherry',
        ];
    }
    
    /**
     * Get GST rate options
     * 
     * @return array
     */
    public static function getGSTRates()
    {
        return [
            0 => 'GST 0% (Essential items)',
            5 => 'GST 5% (Basic necessities)',
            12 => 'GST 12% (Processed foods)',
            18 => 'GST 18% (Standard rate)',
            28 => 'GST 28% (Luxury items)',
        ];
    }
}

