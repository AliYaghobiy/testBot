<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    protected $fillable = [
        'name', 'nameSeo', 'type', 'bodySeo',
        'keyword', 'body', 'slug'
    ];

    public function catables(): MorphMany
    {
        return $this->morphMany(Catable::class, 'catables');
    }
}

