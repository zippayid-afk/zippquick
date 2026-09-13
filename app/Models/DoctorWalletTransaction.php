<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class DoctorWalletTransaction extends Model
{
    use HasFactory;

    const TYPE_CREDIT = 'credit';
    const TYPE_DEBIT = 'debit';
    const STATUS_COMPLETED = 'completed';
    const STATUS_PENDING = 'pending';
    const STATUS_FAILED = 'failed';

    protected $fillable = [
        'doctor_id',
        'appointment_id',
        'type',
        'amount',
        'currency',
        'currency_code',
        'balance_before',
        'balance_after',
        'transaction_date',
        'status',
        'message',
        'txn_id',
        'reference_id',
        'country_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'balance_before' => 'decimal:2',
        'balance_after' => 'decimal:2',
        'transaction_date' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Relationships
     */
    public function doctor()
    {
        return $this->belongsTo(Doctor::class);
    }

    public function appointment()
    {
        return $this->belongsTo(Appointment::class);
    }

    public function country()
    {
        return $this->belongsTo(Country::class);
    }

    /**
     * Scopes
     */
    public function scopeByDoctor($query, $doctorId)
    {
        return $query->where('doctor_id', $doctorId);
    }

    public function scopeByType($query, $type)
    {
        return $query->where('type', $type);
    }

    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    public function scopeCredits($query)
    {
        return $query->where('type', self::TYPE_CREDIT);
    }

    public function scopeDebits($query)
    {
        return $query->where('type', self::TYPE_DEBIT);
    }
}
