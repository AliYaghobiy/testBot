<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $fillable = [
        'title', 'titleSeo', 'bodySeo', 'price', 'slug', 
        'body', 'keyword', 'image', 'status'
    ];

    public function catables(): MorphMany
    {
        return $this->morphMany(Catable::class, 'catables');
    }
}

