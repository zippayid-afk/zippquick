<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Casts\Attribute;
use App\Traits\LogsActivity;

class Order extends Model
{
    use HasFactory, SoftDeletes, LogsActivity;

    public static $activeType = 1;
    public static $previousType = 0;
    protected $casts = [
        'additional_charges' => 'array',
        'total'            => 'float',
        'delivery_charge'  => 'float',
        'tax_amount'       => 'float',
        'tax_percentage'   => 'float',
        'wallet_balance'   => 'float',
        'paid_wallet'      => 'float',
        'discount'         => 'float',
        'promo_discount'   => 'float',
        'cashback_amount'  => 'float',
        'final_total'      => 'float',
        'remaining_total'  => 'float',
        'remaining_final'  => 'float',
        'refund_amount'    => 'float',
        'active_status'    => 'integer',
        'till_status'      => 'integer',
        'surge_charges'      => 'array',
        'address'            => 'array',
        // GST fields
        'cgst_amount'      => 'float',
        'sgst_amount'      => 'float',
        'igst_amount'      => 'float',
        'is_intra_state'   => 'boolean',
    ];

    public static $previousTypeStatus = 0;

    public static function boot()
    {
        parent::boot();
        static::deleting(function ($data) { // before delete() method call this
            $data->items()->delete();
        });
    }

    function getActiveStatusNameAttribute()
    {

    }

    public function items()
    {
        return $this->hasMany(OrderItem::class, 'order_id', 'id');
    }

    public function user()
    {
        return $this->hasOne(User::class, 'id', 'user_id');
    }

    public function orderStatus()
    {
        return $this->hasMany(OrderStatus::class, 'order_id', 'id');
    }

    public function setDeliveryBoyBonusDetailsAttribute($value)
    {
        $this->attributes['delivery_boy_bonus_details'] = json_encode($value);
    }

    public function getDeliveryBoyBonusDetailsAttribute($value)
    {
        return json_decode($value, true);
    }

}
