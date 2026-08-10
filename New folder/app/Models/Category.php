<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class Category extends Model
{
    protected $fillable = ['name', 'code', 'parent_id'];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id')->orderBy('name');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function descendants(): HasMany
    {
        return $this->children()->with('descendants');
    }

    public static function selfAndDescendantIds(int $categoryId): array
    {
        $categories = static::query()->get(['id', 'parent_id']);

        if (! $categories->contains('id', $categoryId)) {
            return [$categoryId];
        }

        return static::collectDescendantIds($categoryId, $categories)
            ->prepend($categoryId)
            ->unique()
            ->values()
            ->all();
    }

    private static function collectDescendantIds(int $categoryId, Collection $categories): Collection
    {
        return $categories
            ->where('parent_id', $categoryId)
            ->pluck('id')
            ->flatMap(fn ($childId) => static::collectDescendantIds((int) $childId, $categories)->prepend((int) $childId));
    }
}
