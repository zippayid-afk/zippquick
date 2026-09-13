<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Prescription extends Model
{
    use HasFactory, SoftDeletes;

    const STATUS_DRAFT = 'draft';
    const STATUS_ISSUED = 'issued';
    const STATUS_FULFILLED = 'fulfilled';
    const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'doctor_id',
        'appointment_id',
        'patient_id',
        'prescription_date',
        'medications',
        'diagnosis',
        'clinical_notes',
        'doctor_signature',
        'seal_image',
        'pdf_file',
        'status',
        'valid_until',
        'special_instructions',
    ];

    protected $casts = [
        'medications' => 'array',
        'prescription_date' => 'datetime',
        'valid_until' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected $appends = [
        'doctor_signature_url',
        'seal_image_url',
        'pdf_file_url',
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

    public function prescriptionOrders()
    {
        return $this->hasMany(PrescriptionOrder::class);
    }

    /**
     * Accessors
     */
    public function getDoctorSignatureUrlAttribute()
    {
        return $this->doctor_signature ? $this->doctor_signature : null;
    }

    public function getSealImageUrlAttribute()
    {
        return $this->seal_image ? $this->seal_image : null;
    }

    public function getPdfFileUrlAttribute()
    {
        return $this->pdf_file ? $this->pdf_file : null;
    }

    /**
     * Scopes
     */
    public function scopeByDoctor($query, $doctorId)
    {
        return $query->where('doctor_id', $doctorId);
    }

    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    public function scopeActive($query)
    {
        return $query->where('status', '!=', self::STATUS_CANCELLED)
            ->where('valid_until', '>=', today());
    }

    public function scopeExpired($query)
    {
        return $query->where('valid_until', '<', today());
    }

    /**
     * Generate prescription PDF
     */
    public function generatePdf()
    {
        // Implementation for PDF generation using Cloudinary
        // Will be implemented in PrescriptionService
    }
}
