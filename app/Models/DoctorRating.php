<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class DoctorRating extends Model
{
    use HasFactory;

    protected $fillable = [
        'doctor_id',
        'appointment_id',
        'patient_id',
        'rating',
        'review',
        'is_verified_appointment',
    ];

    protected $casts = [
        'rating' => 'integer',
        'is_verified_appointment' => 'boolean',
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

    public function patient()
    {
        return $this->belongsTo(User::class, 'patient_id');
    }

    /**
     * Scopes
     */
    public function scopeByDoctor($query, $doctorId)
    {
        return $query->where('doctor_id', $doctorId);
    }

    public function scopeVerified($query)
    {
        return $query->where('is_verified_appointment', true);
    }
}
