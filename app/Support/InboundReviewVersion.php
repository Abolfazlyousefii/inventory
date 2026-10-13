<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;

final class InboundReviewVersion
{
    public static function token(Model $model): string
    {
        $model->loadMissing('items');

        return hash('sha256', json_encode([$model->getAttributes(), $model->items->sortBy('id')->map(fn ($item) => $item->getAttributes())->values()->all()], JSON_THROW_ON_ERROR));
    }
}
