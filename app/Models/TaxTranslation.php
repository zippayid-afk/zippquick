<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TaxTranslation extends Model
{
    use HasFactory;
    public $timestamps = false;
    
    protected $fillable = [
        'tax_id',
        'language_id',
        'title'
    ];

        public function tax()
    {
        return $this->belongsTo(Tax::class);
    }

    public function language()
    {
        return $this->belongsTo(Language::class);
    }
}
