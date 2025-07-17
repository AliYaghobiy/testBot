<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Catable extends Model
{
    protected $fillable = [
        'category_id', 'catables_id', 'catables_type'
    ];

    public function catables()
    {
        return $this->morphTo();
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }
}
