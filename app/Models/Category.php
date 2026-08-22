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

        $children = $categories->groupBy(fn (Category $category) => (int) ($category->parent_id ?? 0));
        $visited = [];
        $queue = [$categoryId];
        while ($queue !== []) {
            $id = (int) array_shift($queue);
            if (isset($visited[$id])) {
                continue;
            }
            $visited[$id] = true;
            foreach ($children->get($id, collect()) as $child) {
                $queue[] = (int) $child->id;
            }
        }

        return array_keys($visited);
    }
}
