<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
//use Illuminate\Database\Eloquent\SoftDeletes;

class OrderItem extends Model
{
    //use HasFactory,SoftDeletes;
    use HasFactory;
    protected $hidden = [];
protected $casts = [
        'id' => 'integer',
        'user_id' => 'integer',
        'order_id' => 'integer',
        'price' => 'float',
        'variant_attributes' => 'array',
        'discounted_price' => 'float',
        'purchase_price' => 'float',
        'sub_total' => 'float',
        'delivery_charge' => 'float',
        'additional_charges' => 'array',
        'surge_charges' => 'array',
        'promo_discount' => 'float',
        'wallet_balance' => 'float',
        'final_total' => 'float',
        'tax_amount' => 'float',
        'tax_percentage' => 'float',
        'quantity' => 'float',
        'product_id' => 'integer',
        'product_variant_id' => 'integer',
        'variant_id' => 'integer',
        'return_days' => 'integer',
        'return_status' => 'integer',
        'cancelable_status' => 'integer',
        'is_credited' => 'integer',
        'delivery_boy_id' => 'integer',
        'delivery_boy_bonus_details' => 'array',
        'delivery_boy_bonus_amount' => 'float',
        'store_id' => 'integer',
        'till_status' => 'integer',
        'active_status' => 'integer',
        // GST fields
        'cgst_amount' => 'float',
        'sgst_amount' => 'float',
        'igst_amount' => 'float',
        'gst_rate' => 'float',
        'gst_inclusive' => 'boolean',
    ];
    protected $appends = ['image_url', 'prescription_url'];

    public function images(){
        return $this->hasMany(ProductImages::class,'product_variant_id','product_variant_id');
          
    }

    public function getImageUrlAttribute(){
        // First check if product_image was set by the query (from products table join)
        $imageSource = $this->attributes['product_image'] ?? $this->attributes['image'] ?? null;
        
        if($imageSource){
            // If image is already a full URL (Cloudinary or external), return as-is
            if (preg_match('~^https?://~', $imageSource)) {
                return $imageSource;
            }
            // Otherwise treat as local storage path
            return asset('storage/' . $imageSource);
        }
        return '';
    }

    public function getPrescriptionUrlAttribute()
    {
        // `prescription` is absent when the row came from a partial select().
        $path = $this->attributes['prescription'] ?? null;
        return $path ? asset('storage/' . $path) : '';
    }

    public function store()
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    public function productVariant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
    public function user()
    {
        return $this->hasOne(User::class, 'id', 'user_id');
    }
}
