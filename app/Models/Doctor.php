<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Doctor extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'admin_id',
        'first_name',
        'last_name',
        'email',
        'mobile',
        'specialization',
        'qualification',
        'license_number',
        'experience_years',
        'bio',
        'profile_image',
        'license_document',
        'certificate_document',
        'is_verified',
        'is_approved',
        'is_active',
        'approval_date',
        'rejection_reason',
        'country_id',
        'zone_id',
    ];

    protected $casts = [
        'is_verified' => 'boolean',
        'is_approved' => 'boolean',
        'is_active' => 'boolean',
        'approval_date' => 'datetime',
        'experience_years' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected $appends = [
        'full_name',
        'profile_image_url',
        'license_document_url',
        'certificate_document_url',
    ];

    /**
     * Relationships
     */
    public function admin()
    {
        return $this->belongsTo(Admin::class);
    }

    public function clinics()
    {
        return $this->hasMany(Clinic::class);
    }

    public function appointments()
    {
        return $this->hasMany(Appointment::class);
    }

    public function prescriptions()
    {
        return $this->hasMany(Prescription::class);
    }

    public function timeSlots()
    {
        return $this->hasMany(DoctorTimeSlot::class);
    }

    public function ratings()
    {
        return $this->hasMany(DoctorRating::class);
    }

    public function wallet()
    {
        return $this->hasOne(DoctorWallet::class);
    }

    public function walletTransactions()
    {
        return $this->hasMany(DoctorWalletTransaction::class);
    }

    public function country()
    {
        return $this->belongsTo(Country::class);
    }

    public function zone()
    {
        return $this->belongsTo(Zone::class);
    }

    /**
     * Accessors
     */
    public function getFullNameAttribute()
    {
        return "{$this->first_name} {$this->last_name}";
    }

    public function getProfileImageUrlAttribute()
    {
        return $this->profile_image ? $this->profile_image : null;
    }

    public function getLicenseDocumentUrlAttribute()
    {
        return $this->license_document ? $this->license_document : null;
    }

    public function getCertificateDocumentUrlAttribute()
    {
        return $this->certificate_document ? $this->certificate_document : null;
    }

    /**
     * Scopes
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeApproved($query)
    {
        return $query->where('is_approved', true);
    }

    public function scopeVerified($query)
    {
        return $query->where('is_verified', true);
    }

    public function scopePending($query)
    {
        return $query->where('is_approved', false);
    }

    public function scopeByCountry($query, $countryId)
    {
        return $query->where('country_id', $countryId);
    }

    public function scopeByZone($query, $zoneId)
    {
        return $query->where('zone_id', $zoneId);
    }

    public function scopeSearch($query, $search)
    {
        return $query->where('first_name', 'like', "%{$search}%")
            ->orWhere('last_name', 'like', "%{$search}%")
            ->orWhere('email', 'like', "%{$search}%")
            ->orWhere('mobile', 'like', "%{$search}%")
            ->orWhere('specialization', 'like', "%{$search}%");
    }

    /**
     * Get doctor's average rating
     */
    public function getAverageRating()
    {
        return $this->ratings()->avg('rating') ?? 0;
    }

    /**
     * Get doctor's total earnings
     */
    public function getTotalEarnings()
    {
        $wallet = $this->wallet;
        if (!$wallet) {
            return 0;
        }
        return $wallet->total_earned ?? 0;
    }

    /**
     * Get doctor's current balance
     */
    public function getCurrentBalance()
    {
        $wallet = $this->wallet;
        if (!$wallet) {
            return 0;
        }
        return $wallet->balance ?? 0;
    }

    /**
     * Get doctor's appointment count
     */
    public function getAppointmentCount($status = null)
    {
        $query = $this->appointments();
        if ($status) {
            $query->where('status', $status);
        }
        return $query->count();
    }
}
