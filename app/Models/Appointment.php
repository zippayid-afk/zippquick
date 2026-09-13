<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Appointment extends Model
{
    use HasFactory, SoftDeletes;

    const STATUS_PENDING = 'pending';
    const STATUS_APPROVED = 'approved';
    const STATUS_COMPLETED = 'completed';
    const STATUS_CANCELLED = 'cancelled';
    const STATUS_REJECTED = 'rejected';

    const TYPE_CLINIC = 'clinic';
    const TYPE_VIDEO = 'video';
    const TYPE_IN_HOUSE = 'in_house';

    protected $fillable = [
        'doctor_id',
        'patient_id',
        'clinic_id',
        'appointment_date',
        'appointment_time',
        'duration_minutes',
        'type',
        'status',
        'patient_name',
        'patient_email',
        'patient_phone',
        'symptoms',
        'notes',
        'google_meet_link',
        'google_meet_id',
        'is_rescheduled',
        'original_appointment_id',
        'cancellation_reason',
        'prescription_id',
        'amount',
        'currency',
        'payment_status',
    ];

    protected $casts = [
        'appointment_date' => 'date',
        'appointment_time' => 'datetime',
        'is_rescheduled' => 'boolean',
        'amount' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Relationships
     */
    public function doctor()
    {
        return $this->belongsTo(Doctor::class);
    }

    public function patient()
    {
        return $this->belongsTo(User::class, 'patient_id');
    }

    public function clinic()
    {
        return $this->belongsTo(Clinic::class);
    }

    public function prescription()
    {
        return $this->belongsTo(Prescription::class);
    }

    public function originalAppointment()
    {
        return $this->belongsTo(Appointment::class, 'original_appointment_id');
    }

    public function rescheduledAppointments()
    {
        return $this->hasMany(Appointment::class, 'original_appointment_id');
    }

    public function messages()
    {
        return $this->hasMany(ConsultationMessage::class);
    }

    /**
     * Scopes
     */
    public function scopeUpcoming($query)
    {
        return $query->where('appointment_date', '>=', today())
            ->whereIn('status', [self::STATUS_PENDING, self::STATUS_APPROVED]);
    }

    public function scopePast($query)
    {
        return $query->where('appointment_date', '<', today());
    }

    public function scopeByDoctor($query, $doctorId)
    {
        return $query->where('doctor_id', $doctorId);
    }

    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    public function scopeByType($query, $type)
    {
        return $query->where('type', $type);
    }

    public function scopeByDate($query, $date)
    {
        return $query->whereDate('appointment_date', $date);
    }

    public function scopeSearch($query, $search)
    {
        return $query->where('patient_name', 'like', "%{$search}%")
            ->orWhere('patient_email', 'like', "%{$search}%")
            ->orWhere('patient_phone', 'like', "%{$search}%");
    }

    /**
     * Check if appointment can be cancelled
     */
    public function canBeCancelled()
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_APPROVED]);
    }

    /**
     * Check if appointment can be rescheduled
     */
    public function canBeRescheduled()
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_APPROVED]);
    }
}
