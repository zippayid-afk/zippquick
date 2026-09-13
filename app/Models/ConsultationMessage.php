<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class ConsultationMessage extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'appointment_id',
        'sender_id',
        'sender_type',
        'message',
        'attachment',
        'attachment_type',
        'is_read',
        'read_at',
    ];

    protected $casts = [
        'is_read' => 'boolean',
        'read_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected $appends = [
        'attachment_url',
    ];

    /**
     * Relationships
     */
    public function appointment()
    {
        return $this->belongsTo(Appointment::class);
    }

    public function sender()
    {
        return $this->morphTo('sender');
    }

    /**
     * Accessors
     */
    public function getAttachmentUrlAttribute()
    {
        return $this->attachment ? $this->attachment : null;
    }

    /**
     * Scopes
     */
    public function scopeByAppointment($query, $appointmentId)
    {
        return $query->where('appointment_id', $appointmentId);
    }

    public function scopeUnread($query)
    {
        return $query->where('is_read', false);
    }
}
