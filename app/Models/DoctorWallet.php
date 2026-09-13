<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class DoctorWallet extends Model
{
    use HasFactory;

    protected $fillable = [
        'doctor_id',
        'balance',
        'total_earned',
        'total_withdrawn',
        'currency',
        'country_id',
    ];

    protected $casts = [
        'balance' => 'decimal:2',
        'total_earned' => 'decimal:2',
        'total_withdrawn' => 'decimal:2',
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

    public function country()
    {
        return $this->belongsTo(Country::class);
    }

    public function transactions()
    {
        return $this->hasMany(DoctorWalletTransaction::class);
    }
}
