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

    /**
     * دریافت دسته‌های مادر از جدول catables
     */
    public function getParentCategories(): array
    {
        $parentIds = \DB::table('catables')
            ->where('catables_id', $this->id)
            ->where('catables_type', Category::class)
            ->pluck('category_id')
            ->toArray();

        return Category::whereIn('id', $parentIds)->get()->toArray();
    }

    /**
     * دریافت تمام دسته‌های مادر به صورت بازگشتی
     */
    public function getAllParentCategories(): array
    {
        $allParents = [];
        $visited = [];

        return $this->getParentCategoriesRecursive($visited);
    }

    /**
     * متد کمکی برای دریافت والدین به صورت بازگشتی
     */
    private function getParentCategoriesRecursive(array &$visited): array
    {
        // جلوگیری از حلقه بی‌نهایت
        if (in_array($this->id, $visited)) {
            return [];
        }

        $visited[] = $this->id;

        $parentIds = \DB::table('catables')
            ->where('catables_id', $this->id)
            ->where('catables_type', Category::class)
            ->pluck('category_id')
            ->toArray();

        if (empty($parentIds)) {
            return [];
        }

        $directParents = Category::whereIn('id', $parentIds)->get();
        $allParents = [];

        foreach ($directParents as $parent) {
            // ابتدا والدین بالاتر را اضافه می‌کنیم (کلی‌تر)
            $grandParents = $parent->getParentCategoriesRecursive($visited);
            $allParents = array_merge($allParents, $grandParents);

            // سپس خود والد مستقیم را اضافه می‌کنیم
            $allParents[] = $parent->toArray();
        }

        // حذف دوبله‌ها بر اساس ID
        $uniqueParents = [];
        $seenIds = [];

        foreach ($allParents as $parent) {
            if (!in_array($parent['id'], $seenIds)) {
                $uniqueParents[] = $parent;
                $seenIds[] = $parent['id'];
            }
        }

        return $uniqueParents;
    }

    /**
     * دریافت کل مسیر سلسله مراتبی دسته‌بندی با ترتیب صحیح
     */
    public function getCategoryPath(): array
    {
        $parents = $this->getAllParentCategories();

        // والدین به ترتیب صحیح هستند (از کلی به خاص)
        // اکنون فقط خود دسته را در انتها اضافه می‌کنیم
        $parents[] = $this->toArray();

        return $parents;
    }
}
