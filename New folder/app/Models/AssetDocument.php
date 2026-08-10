<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssetDocument extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_FINALIZED = 'finalized';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'document_number',
        'document_date',
        'personnel_id',
        'trustee_user_id',
        'trustee_name_snapshot',
        'status',
        'description',
        'signed_form_path',
        'signed_form_original_name',
        'signed_form_mime',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'document_date' => 'date',
    ];

    public static function statuses(): array
    {
        return [self::STATUS_DRAFT, self::STATUS_FINALIZED, self::STATUS_CANCELLED];
    }

    public static function statusLabels(): array
    {
        return [
            self::STATUS_DRAFT => 'پیش‌نویس',
            self::STATUS_FINALIZED => 'نهایی‌شده',
            self::STATUS_CANCELLED => 'لغو شده',
        ];
    }

    public function personnel()
    {
        return $this->belongsTo(AssetPersonnel::class, 'personnel_id');
    }

    public function trusteeUser()
    {
        return $this->belongsTo(User::class, 'trustee_user_id');
    }

    public function trusteeDisplayName(): string
    {
        return $this->trustee_name_snapshot
            ?: ($this->trusteeUser?->name
                ?: ($this->personnel?->full_name ?: '—'));
    }

    public function items()
    {
        return $this->hasMany(AssetDocumentItem::class, 'document_id');
    }

    public function histories()
    {
        return $this->hasMany(AssetDocumentHistory::class, 'document_id')->latest('done_at');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
