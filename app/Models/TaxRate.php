<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaxRate extends Model
{
    protected $table = 'tax_rates';

    protected $fillable = [
        'name',
        'description',
        'rate',
        'cgst_rate',
        'sgst_rate',
        'igst_rate',
        'category',
        'hsn_code',
        'is_active',
        'is_default',
    ];

    protected $casts = [
        'rate' => 'float',
        'cgst_rate' => 'float',
        'sgst_rate' => 'float',
        'igst_rate' => 'float',
        'is_active' => 'boolean',
        'is_default' => 'boolean',
    ];

    /**
     * Get active tax rates
     */
    public static function getActive()
    {
        return self::where('is_active', true)->orderBy('rate')->get();
    }

    /**
     * Get default tax rate
     */
    public static function getDefault()
    {
        return self::where('is_default', true)->where('is_active', true)->first();
    }

    /**
     * Get tax rate by percentage
     */
    public static function getByRate($rate)
    {
        return self::where('rate', $rate)->where('is_active', true)->first();
    }

    /**
     * Validate CGST = SGST for intra-state compliance
     */
    public static function boot()
    {
        parent::boot();

        static::saving(function ($model) {
            // Validate CGST = SGST (must be equal for Indian GST)
            if ((float) $model->cgst_rate !== (float) $model->sgst_rate) {
                $model->cgst_rate = $model->rate / 2;
                $model->sgst_rate = $model->rate / 2;
            }

            // Validate IGST = CGST + SGST
            if ((float) $model->igst_rate !== ((float) $model->cgst_rate + (float) $model->sgst_rate)) {
                $model->igst_rate = (float) $model->cgst_rate + (float) $model->sgst_rate;
            }
        });
    }
}

