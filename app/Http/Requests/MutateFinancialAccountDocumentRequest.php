<?php

namespace App\Http\Requests;

class MutateFinancialAccountDocumentRequest extends StoreFinancialAccountDocumentRequest
{
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();
        $this->merge(['reason' => is_string($this->input('reason')) ? trim($this->input('reason')) : $this->input('reason')]);
    }

    public function rules(): array
    {
        $version = ['version' => ['required', 'integer', 'min:1']];
        if ($this->isMethod('DELETE')) {
            return $version + ['reason' => ['required', 'string', 'min:3', 'max:2000']];
        }
        $rules = parent::rules();
        unset($rules['submission_token'], $rules['account_role']);

        return $rules + $version;
    }

    public function attributes(): array
    {
        return parent::attributes() + ['reason' => 'علت حذف', 'version' => 'نسخه سند'];
    }
}
