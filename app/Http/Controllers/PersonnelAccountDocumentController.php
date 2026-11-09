<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePersonnelAccountDocumentRequest;
use App\Models\{ActivityLog, Customer, CustomerLedger, PersonnelAccountDocument};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PersonnelAccountDocumentController extends Controller
{
    public function store(Customer $customer, StorePersonnelAccountDocumentRequest $request)
    {
        $data = $request->validated();
        DB::transaction(function () use ($customer, $request, $data) {
            // Serialize submissions for the same person, including the first document.
            Customer::whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $values = ['customer_id' => $customer->id, 'type' => $data['type'],
                'amount' => (int) $data['document_amount'], 'document_date' => $data['document_date'],
                'description' => trim($data['description']), 'reference' => trim($data['reference'] ?? '') ?: null,
                'created_by' => $request->user()->id];
            $existing = PersonnelAccountDocument::where('submission_token', $data['submission_token'])->first();
            if ($existing) {
                $previous = $existing->only(array_keys($values));
                $previous['document_date'] = $existing->document_date->format('Y-m-d');
                if ($previous != $values) {
                    throw ValidationException::withMessages(['submission_token' => 'این فرم قبلاً با اطلاعات دیگری ثبت شده است؛ صفحه را تازه‌سازی کنید.']);
                }
                return;
            }
            $document = PersonnelAccountDocument::create($values + ['submission_token' => $data['submission_token']]);
            $ledger = CustomerLedger::create(['customer_id' => $customer->id, 'type' => $document->type,
                'amount' => $document->amount, 'reference_type' => PersonnelAccountDocument::class,
                'reference_id' => $document->id,
                'note' => mb_substr('سند پرسنلی PER-'.$document->id.' · '.$document->description, 0, 255)]);
            ActivityLog::create(['user_id' => $request->user()->id, 'action' => 'personnel_account_document_created',
                'subject_type' => Customer::class, 'subject_id' => $customer->id, 'description' => 'سند پرسنلی در گردش شخص ثبت شد.',
                'properties' => ['document_id' => $document->id, 'ledger_id' => $ledger->id, 'document' => $values],
                'occurred_at' => now()]);
        }, 3);

        return redirect()->route('account-statements.show', $customer)->with('success', 'سند پرسنلی ثبت و مبلغ آن در گردش همین شخص اعمال شد.');
    }
}
