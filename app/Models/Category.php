<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    protected $fillable = [
        'name', 'nameSeo', 'type', 'bodySeo',
        'keyword', 'body', 'slug'
    ];

    public function catables(): HasMany
    {
        return $this->hasMany(Catable::class);
    }

    public function products()
    {
        return $this->hasManyThrough(Product::class, Catable::class, 'category_id', 'id', 'id', 'catables_id')
            ->where('catables_type', Product::class);
    }
}

