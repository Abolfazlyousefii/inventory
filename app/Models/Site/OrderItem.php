<?php

namespace App\Models\Site;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    protected $connection = 'site';

    protected $table = 'order_items';

    protected $guarded = ['id'];

    protected $casts = [
        'notes' => 'array',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function get_price(): BelongsTo
    {
        return $this->belongsTo(Price::class, 'price_id', 'id');
    }

    public function originalUnitPrice(): int
    {
        $realPrice = (int) $this->real_price;
        $price = (int) $this->price;

        return max($realPrice > 0 ? $realPrice : $price, 0);
    }

    public function finalUnitPrice(): int
    {
        $base = $this->originalUnitPrice();
        $storedPrice = max((int) $this->price, 0);
        $discount = max((float) $this->discount, 0);
        $discountType = strtolower(trim((string) ($this->discount_type ?? 'percent')));

        if ($discount <= 0) {
            return $storedPrice > 0 ? $storedPrice : $base;
        }

        // در سفارش‌های قدیمی price از قبل قیمت نهایی بوده است.
        if ($storedPrice > 0 && $storedPrice < $base) {
            return $storedPrice;
        }

        if ($discountType === 'percent') {
            $percent = min($discount, 100);

            return max((int) round($base * ((100 - $percent) / 100)), 0);
        }

        return max((int) round($base - $discount), 0);
    }

    public function notesArray(): array
    {
        $notes = $this->notes;

        if (is_array($notes)) {
            return $notes;
        }

        $raw = $this->getRawOriginal('notes');
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Merge an ERP audit payload into order_items.notes without removing
     * previous site-side data. The history is idempotent by event_key.
     */
    public function mergeErpNotes(array $sections, array $historyEntry): void
    {
        $notes = $this->notesArray();
        $notes['schema_version'] = max((int) ($notes['schema_version'] ?? 0), 1);

        foreach ($sections as $key => $value) {
            if (! is_array($value)) {
                $notes[$key] = $value;
                continue;
            }

            $current = isset($notes[$key]) && is_array($notes[$key])
                ? $notes[$key]
                : [];

            $notes[$key] = array_replace_recursive($current, $value);
        }

        $history = isset($notes['history']) && is_array($notes['history'])
            ? array_values($notes['history'])
            : [];

        $eventKey = (string) ($historyEntry['event_key'] ?? '');
        $alreadyExists = $eventKey !== '' && collect($history)->contains(
            fn ($row) => is_array($row) && (string) ($row['event_key'] ?? '') === $eventKey
        );

        if (! $alreadyExists) {
            $history[] = $historyEntry;
        }

        // جلوگیری از رشد نامحدود ستون notes.
        $notes['history'] = array_slice($history, -50);

        $this->forceFill(['notes' => $notes])->saveQuietly();
    }
}
