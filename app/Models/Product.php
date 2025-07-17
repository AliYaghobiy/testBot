<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Product extends Model
{
    protected $fillable = [
        'title', 'titleSeo', 'bodySeo', 'price', 'slug', 
        'body', 'keyword', 'image', 'status'
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'status' => 'boolean',
    ];

    public function catables(): MorphMany
    {
        return $this->morphMany(Catable::class, 'catables');
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'catables', 'catables_id', 'category_id')
            ->wherePivot('catables_type', static::class);
    }

    public function getPrimaryCategory(): ?Category
    {
        return $this->categories()->first();
    }
}

