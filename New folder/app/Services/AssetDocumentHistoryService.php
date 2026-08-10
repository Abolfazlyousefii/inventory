<?php

namespace App\Services;

use App\Models\AssetDocument;
use App\Models\AssetDocumentHistory;

class AssetDocumentHistoryService
{
    public function log(AssetDocument $document, string $actionType, ?array $old = null, ?array $new = null, ?string $description = null, ?int $doneBy = null): AssetDocumentHistory
    {
        return AssetDocumentHistory::query()->create([
            'document_id' => $document->id,
            'action_type' => $actionType,
            'old_value' => $old,
            'new_value' => $new,
            'description' => $description,
            'done_by' => $doneBy,
            'done_at' => now(),
        ]);
    }
}
