<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Clinic extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'doctor_id',
        'name',
        'address',
        'city',
        'state',
        'postal_code',
        'phone',
        'email',
        'website',
        'clinic_image',
        'registration_number',
        'license_number',
        'is_verified',
        'is_active',
        'country_id',
        'zone_id',
        'opening_hours_monday_start',
        'opening_hours_monday_end',
        'opening_hours_tuesday_start',
        'opening_hours_tuesday_end',
        'opening_hours_wednesday_start',
        'opening_hours_wednesday_end',
        'opening_hours_thursday_start',
        'opening_hours_thursday_end',
        'opening_hours_friday_start',
        'opening_hours_friday_end',
        'opening_hours_saturday_start',
        'opening_hours_saturday_end',
        'opening_hours_sunday_start',
        'opening_hours_sunday_end',
    ];

    protected $casts = [
        'is_verified' => 'boolean',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected $appends = [
        'clinic_image_url',
        'opening_hours',
    ];

    /**
     * Relationships
     */
    public function doctor()
    {
        return $this->belongsTo(Doctor::class);
    }

    public function appointments()
    {
        return $this->hasMany(Appointment::class);
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
    public function getClinicImageUrlAttribute()
    {
        return $this->clinic_image ? $this->clinic_image : null;
    }

    public function getOpeningHoursAttribute()
    {
        $days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
        $hours = [];
        
        foreach ($days as $day) {
            $startColumn = "opening_hours_{$day}_start";
            $endColumn = "opening_hours_{$day}_end";
            
            if ($this->$startColumn && $this->$endColumn) {
                $hours[$day] = [
                    'start' => $this->$startColumn,
                    'end' => $this->$endColumn,
                ];
            }
        }
        
        return $hours;
    }

    /**
     * Scopes
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeVerified($query)
    {
        return $query->where('is_verified', true);
    }

    public function scopeByDoctor($query, $doctorId)
    {
        return $query->where('doctor_id', $doctorId);
    }

    public function scopeByCountry($query, $countryId)
    {
        return $query->where('country_id', $countryId);
    }

    public function scopeSearch($query, $search)
    {
        return $query->where('name', 'like', "%{$search}%")
            ->orWhere('address', 'like', "%{$search}%")
            ->orWhere('city', 'like', "%{$search}%")
            ->orWhere('phone', 'like', "%{$search}%");
    }
}
