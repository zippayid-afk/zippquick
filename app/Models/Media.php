<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Media extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'extension',
        'type',
        'sub_directory',
        'size',
        'store_id',
    ];

    protected $appends = ['url'];

    /**
     * Get the full URL for the media file
     * Handles both Cloudinary URLs and local storage paths
     */
    public function getUrlAttribute()
    {
        // If sub_directory contains a Cloudinary URL, return it directly
        if (!empty($this->sub_directory) && preg_match('~^https?://~', $this->sub_directory)) {
            return $this->sub_directory;
        }

        // Otherwise construct the local storage URL
        if (!empty($this->sub_directory)) {
            return asset('storage/' . $this->sub_directory . $this->name);
        }

        return null;
    }
}

