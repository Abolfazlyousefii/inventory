<?php

namespace App\Http\Requests;

class UpdateSalesReturnRequest extends StoreSalesReturnRequest
{
    public function rules(): array
    {
        $pending = $this->route('document')?->isPendingWarehouse() ?? false;

        return parent::rules() + [
            'document_version' => [$pending ? 'required' : 'nullable', 'string', 'size:64'],
            'adjustment_reason' => [$pending ? 'required' : 'nullable', 'string', 'min:3', 'max:1000'],
        ];
    }
}
