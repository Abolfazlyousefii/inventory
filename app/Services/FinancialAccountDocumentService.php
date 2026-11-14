<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerLedger;
use App\Models\FinancialAccountDocument;
use App\Models\Supplier;
use App\Models\SupplierLedger;
use App\Support\ActivityLogger;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FinancialAccountDocumentService
{
    public function revise(FinancialAccountDocument $document, array $data, int $actorId, bool $cancel = false): FinancialAccountDocument
    {
        return DB::transaction(function () use ($document, $data, $actorId, $cancel) {
            $account = $document->customer_id ? Customer::findOrFail($document->customer_id) : Supplier::findOrFail($document->supplier_id);
            $account->newQuery()->whereKey($account->id)->lockForUpdate()->firstOrFail();
            $doc = FinancialAccountDocument::whereKey($document->id)->lockForUpdate()->firstOrFail();
            if ($doc->cancelled_at || $doc->version !== (int) $data['version']) {
                throw ValidationException::withMessages(['version' => 'سند قبلاً تغییر کرده یا حذف شده است؛ صفحه را تازه کنید.']);
            }
            $ledgerClass = $doc->customer_id ? CustomerLedger::class : SupplierLedger::class;
            $accountKey = $doc->customer_id ? 'customer_id' : 'supplier_id';
            $entries = $ledgerClass::where('reference_type', FinancialAccountDocument::class)->where('reference_id', $doc->id)->lockForUpdate()->get();
            if ($entries->count() !== 1 || (int) $entries->first()->getAttribute($accountKey) !== (int) $account->id
                || (int) $entries->first()->amount !== $doc->amount || $entries->first()->type !== $doc->type) {
                throw ValidationException::withMessages(['document' => 'ارتباط سند و گردش حساب هماهنگ نیست؛ ابتدا بررسی مالی انجام شود.']);
            }
            $before = $doc->toArray();
            if ($cancel) {
                $doc->cancelled_at = now();
            } else {
                $doc->fill(['type' => $data['type'], 'amount' => (int) $data['document_amount'],
                    'title' => $data['title'], 'description' => $data['description'], 'document_date' => $data['document_date']]);
            }
            $doc->version++;
            $doc->save();
            $entries->first()->update(['type' => $doc->type, 'amount' => $cancel ? 0 : $doc->amount,
                'note' => mb_substr(($cancel ? 'حذف سند مالی ' : 'سند مالی ').'FIN-'.$doc->id.' · '.$doc->title, 0, 255)]);
            ActivityLogger::logForActor($actorId, $cancel ? 'financial_account_document_cancelled' : 'financial_account_document_updated', $doc,
                $cancel ? 'حذف اثر سند مالی از گردش حساب.' : 'ویرایش سند مالی در گردش حساب.',
                ['before' => $before, 'after' => $doc->toArray(), 'reason' => $data['reason'] ?? null, 'ledger_id' => $entries->first()->id]);

            return $doc;
        }, 3);
    }

    public function create(Customer|Supplier $account, array $data, int $actorId): FinancialAccountDocument
    {
        $values = ['customer_id' => $account instanceof Customer ? $account->id : null,
            'supplier_id' => $account instanceof Supplier ? $account->id : null, 'type' => $data['type'],
            'amount' => (int) $data['document_amount'], 'title' => $data['title'], 'description' => $data['description'],
            'document_date' => $data['document_date'], 'created_by' => $actorId];
        try {
            return DB::transaction(function () use ($account, $data, $values, $actorId) {
                $account->newQuery()->whereKey($account->id)->lockForUpdate()->firstOrFail();
                $existing = FinancialAccountDocument::where('submission_token', $data['submission_token'])->lockForUpdate()->first();
                if ($existing) {
                    $previous = $existing->only(array_keys($values));
                    $previous['document_date'] = $existing->document_date->format('Y-m-d');
                    if ($previous != $values) {
                        throw ValidationException::withMessages(['submission_token' => 'این فرم قبلاً با اطلاعات دیگری ثبت شده است؛ صفحه را تازه کنید.']);
                    }

                    return $existing;
                }
                $doc = FinancialAccountDocument::create($values + ['submission_token' => $data['submission_token']]);
                $ledgerClass = $account instanceof Customer ? CustomerLedger::class : SupplierLedger::class;
                $key = $account instanceof Customer ? 'customer_id' : 'supplier_id';
                $ledger = $ledgerClass::create([$key => $account->id, 'type' => $doc->type, 'amount' => $doc->amount,
                    'reference_type' => FinancialAccountDocument::class, 'reference_id' => $doc->id,
                    'note' => mb_substr('سند مالی FIN-'.$doc->id.' · '.$doc->title, 0, 255)]);
                ActivityLogger::logForActor($actorId, 'financial_account_document_created', $doc, 'ثبت سند مالی در گردش شخص.', [
                    'account_type' => $key, 'account_id' => $account->id, 'ledger_id' => $ledger->id, 'actor_id' => $actorId,
                    'type' => $doc->type, 'amount' => $doc->amount, 'title' => $doc->title, 'description' => $doc->description]);

                return $doc;
            }, 3);
        } catch (UniqueConstraintViolationException $e) {
            if (! str_contains($e->getMessage(), 'submission_token')) {
                throw $e;
            }
            throw ValidationException::withMessages(['submission_token' => 'این درخواست هم‌زمان ثبت شده است؛ صفحه را تازه کنید و گردش را بررسی کنید.']);
        }
    }
}
