<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use RuntimeException;
use WeakMap;

class ActivityLog extends Model
{
    /**
     * Legacy production tables store the JSON in `properties2`, while
     * newer schemas use `properties`. Cache the physical column once per
     * DB connection; this must not scan the activity_logs rows.
     *
     * @var WeakMap<object, string>|null
     */
    private static ?WeakMap $propertiesColumns = null;

    protected $fillable = [
        'user_id',
        'action',
        'subject_type',
        'subject_id',
        'description',
        'properties',
        'occurred_at',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
    ];

    protected function properties(): Attribute
    {
        return Attribute::make(
            get: static function ($value, array $attributes): ?array {
                $raw = $attributes['properties'] ?? $attributes['properties2'] ?? $value;

                if ($raw === null || $raw === '') {
                    return null;
                }

                return is_array($raw) ? $raw : json_decode((string) $raw, true);
            },
            set: fn ($value): array => [
                $this->propertiesColumn() => $value === null
                    ? null
                    : (is_string($value) ? $value : json_encode($value, JSON_THROW_ON_ERROR)),
            ],
        );
    }

    private function propertiesColumn(): string
    {
        $connection = $this->getConnection();
        self::$propertiesColumns ??= new WeakMap;

        if (! isset(self::$propertiesColumns[$connection])) {
            $schema = $connection->getSchemaBuilder();
            $column = $schema->hasColumn($this->getTable(), 'properties')
                ? 'properties'
                : 'properties2';

            if (! $schema->hasColumn($this->getTable(), $column)) {
                throw new RuntimeException('activity_logs has neither properties nor properties2 column.');
            }

            self::$propertiesColumns[$connection] = $column;
        }

        return self::$propertiesColumns[$connection];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
