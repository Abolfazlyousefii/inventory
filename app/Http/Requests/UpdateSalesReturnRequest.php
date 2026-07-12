<?php

namespace App\Http\Requests;

class UpdateSalesReturnRequest extends StoreSalesReturnRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission('sales_returns.edit_draft');
    }
}
