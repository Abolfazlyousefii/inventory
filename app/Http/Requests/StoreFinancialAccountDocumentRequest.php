<?php

namespace App\Http\Requests;

use App\Support\AccountStatementAccess;
use App\Support\JalaliDate;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Http\FormRequest;

class StoreFinancialAccountDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() && ! AccountStatementAccess::readOnly($this->user())
            && PermissionCatalog::userHasPermission($this->user(), 'account_statements.adjust');
    }

    protected function prepareForValidation(): void
    {
        $latin = fn ($value) => strtr(is_scalar($value) ? (string) $value : '', array_combine(preg_split('//u', '۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩', -1, PREG_SPLIT_NO_EMPTY), str_split('01234567890123456789')));
        $date = trim($latin($this->input('document_date')));
        if (preg_match('/^1[34]\d{2}[\/-]/', $date)) {
            $date = JalaliDate::toGregorianDate($date) ?: 'invalid';
        }
        $this->merge(['document_amount' => preg_replace('/[\s,٬،]+/u', '', $latin($this->input('document_amount'))),
            'document_date' => $date, 'title' => is_string($this->input('title')) ? trim($this->input('title')) : $this->input('title'),
            'description' => is_string($this->input('description')) ? trim($this->input('description')) : $this->input('description'),
            'submission_token' => is_string($this->input('submission_token')) ? strtolower($this->input('submission_token')) : $this->input('submission_token')]);
    }

    public function rules(): array
    {
        return ['type' => ['required', 'in:debit,credit'], 'title' => ['required', 'string', 'max:190'],
            'description' => ['required', 'string', 'min:3', 'max:2000'],
            'document_amount' => ['required', 'regex:/^\d{1,16}$/', 'integer', 'min:1', 'max:1000000000000000'],
            'document_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'account_role' => ['nullable', 'in:customer,supplier'], 'submission_token' => ['required', 'uuid']];
    }

    public function attributes(): array
    {
        return ['type' => 'نوع سند', 'title' => 'عنوان سند', 'description' => 'توضیح سند', 'document_amount' => 'مبلغ سند', 'document_date' => 'تاریخ سند', 'account_role' => 'دفتر حساب'];
    }
}
