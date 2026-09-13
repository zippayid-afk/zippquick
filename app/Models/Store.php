<?php

namespace App\Models;

use App\Traits\HasTranslations;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\LogsActivity;

class Store extends Model
{
    use HasFactory, HasTranslations, SoftDeletes, LogsActivity;

    protected $table = 'stores';
    protected $guarded = [];

    protected $casts = [
        'operating_hours' => 'array',
        'zone_id'         => 'integer',
        'status'          => 'integer',
    ];

    protected $translatable = [
        'name',
        'provider',
        'address',
    ];

    protected $translationModel = 'StoreTranslation';
    protected $translationForeignKey = 'store_id';

    protected $hidden = ['deleted_at'];

    public static $statusActive = 1;
    public static $statusInactive = 0;

    public function translations()
    {
        return $this->hasMany(StoreTranslation::class, 'store_id');
    }

    /** All store-panel login users (admins) belonging to this store. */
    public function admins()
    {
        return $this->hasMany(Admin::class, 'store_id');
    }

    /** The primary login admin created with the store. */
    public function owner()
    {
        return $this->belongsTo(Admin::class, 'owner_admin_id');
    }

    /**
     * A store has exactly ONE zone. The zone's own sales_channel decides which
     * channel(s) the store serves — a store fulfilling both points at a 'both' zone.
     */
    public function zone()
    {
        return $this->belongsTo(Zone::class, 'zone_id');
    }

    /**
     * Constrain a store query to those serving $channel, via their zone.
     * Replaces the old zoneColumn()/zoneCoalesceSql() pair.
     */
    public function scopeServingChannel($query, ?string $channel)
    {
        if ($channel === null || !in_array($channel, Zone::REAL_CHANNELS, true)) {
            return $query;
        }
        return $query->whereHas('zone', fn ($q) => $q->serving($channel));
    }
}
