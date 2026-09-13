<?php

namespace App\Models;
use App\Traits\HasTranslations;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\LogsActivity;

class Brand extends Model
{
     use HasFactory,HasTranslations, LogsActivity;

       protected $fillable = [
        'name',
        'image',
        'status',
    ];
    protected $translatable = [
        'name',
    ];
    protected $translationModel = 'BrandTranslation';
    protected $translationForeignKey = 'brand_id';

    protected $appends = ['image_url', 'translations'];

    public function getImageUrlAttribute(){
        $image_url = '';
        if($this->image){
            // If image is a full URL (Cloudinary), return as-is
            if (preg_match('~^https?://~', $this->image)) {
                $image_url = $this->image;
            } else {
                // Otherwise treat as local storage path
                $image_url = asset('storage/'.$this->image);
            }
        }
        return $image_url;
    }
     public function translations()
    {
        return $this->hasMany(BrandTranslation::class);
    }

    public function products()
    {
        return $this->hasMany(Product::class, 'brand_id', 'id');
    }
    

}
