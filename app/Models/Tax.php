<?php

namespace App\Models;

use App\Traits\HasTranslations;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\LogsActivity;

class Tax extends Model
{
    use HasFactory,HasTranslations, LogsActivity;

    protected $table = 'taxes';
    public $timestamps = false;

    protected $fillable = [
        'title',
        'percentage',
        'status'
    ];
    
    protected $appends = ['translations'];
    protected $hidden = [];

     protected $translatable = [
        'title',
    ];

    protected $translationModel = 'TaxTranslation';

    protected $translationForeignKey = 'tax_id';
    public function translations()
{
    return $this->hasMany(TaxTranslation::class);
}
    
}
