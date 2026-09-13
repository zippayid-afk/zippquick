<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class PrescriptionOrder extends Model
{
    use HasFactory, SoftDeletes;

    const STATUS_PENDING = 'pending';
    const STATUS_APPROVED = 'approved';
    const STATUS_FULFILLED = 'fulfilled';
    const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'prescription_id',
        'pharmacy_id',
        'order_status',
        'ordered_date',
        'fulfilled_date',
        'total_amount',
        'currency',
        'delivery_address',
        'delivery_phone',
        'notes',
    ];

    protected $casts = [
        'ordered_date' => 'datetime',
        'fulfilled_date' => 'datetime',
        'total_amount' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Relationships
     */
    public function prescription()
    {
        return $this->belongsTo(Prescription::class);
    }

    /**
     * Scopes
     */
    public function scopeByStatus($query, $status)
    {
        return $query->where('order_status', $status);
    }

    public function scopePending($query)
    {
        return $query->where('order_status', self::STATUS_PENDING);
    }
}
