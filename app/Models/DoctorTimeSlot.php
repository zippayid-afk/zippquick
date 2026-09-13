<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class DoctorTimeSlot extends Model
{
    use HasFactory;

    protected $fillable = [
        'doctor_id',
        'clinic_id',
        'day_of_week',
        'start_time',
        'end_time',
        'slot_duration_minutes',
        'max_patients_per_slot',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
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

    public function clinic()
    {
        return $this->belongsTo(Clinic::class);
    }

    /**
     * Scopes
     */
    public function scopeByDoctor($query, $doctorId)
    {
        return $query->where('doctor_id', $doctorId);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByDay($query, $day)
    {
        return $query->where('day_of_week', $day);
    }
}
