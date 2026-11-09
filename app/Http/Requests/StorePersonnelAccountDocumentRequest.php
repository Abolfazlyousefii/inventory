<?php

namespace App\Http\Requests;

use App\Support\{AccountStatementAccess, JalaliDate, PermissionCatalog};
use Illuminate\Foundation\Http\FormRequest;

class StorePersonnelAccountDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() && !AccountStatementAccess::readOnly($this->user())
            && PermissionCatalog::userHasPermission($this->user(), 'account_statements.adjust');
    }

    protected function prepareForValidation(): void
    {
        $latin = static fn ($value) => strtr((string) $value, array_combine(
            preg_split('//u', '۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩', -1, PREG_SPLIT_NO_EMPTY), str_split('01234567890123456789')));
        $amount = is_scalar($this->input('document_amount')) ? $latin($this->input('document_amount')) : '';
        $date = is_scalar($this->input('document_date')) ? trim($latin($this->input('document_date'))) : '';
        if (preg_match('/^1[34]\d{2}\/\d{1,2}\/\d{1,2}$/', $date)) {
            $date = JalaliDate::toGregorianDate($date) ?: 'invalid';
        }
        $this->merge(['document_amount' => preg_replace('/[\s,٬،]+/u', '', $amount), 'document_date' => $date]);
    }

    public function rules(): array
    {
        return [
            'type' => ['required', 'in:debit,credit'],
            'document_amount' => ['required', 'regex:/^\d{1,16}$/', 'integer', 'min:1', 'max:1000000000000000'],
            'document_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'description' => ['required', 'string', 'min:3', 'max:2000'],
            'reference' => ['nullable', 'string', 'max:190'],
            'submission_token' => ['required', 'uuid'],
        ];
    }

    public function attributes(): array
    {
        return ['document_amount' => 'مبلغ سند', 'document_date' => 'تاریخ سند', 'description' => 'شرح سند', 'reference' => 'شماره سند سازه‌حساب'];
    }
}
