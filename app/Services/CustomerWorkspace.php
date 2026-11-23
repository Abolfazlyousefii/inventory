<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerLedger;
use App\Models\Invoice;
use App\Models\PreinvoiceOrder;
use App\Models\SalesReturnDocument;
use App\Models\User;
use App\Support\PhoneNormalizer;
use App\Support\ProductFinderSearchNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** Read models only: customer ownership never implies permission to mutate financial documents. */
class CustomerWorkspace
{
    public function __construct(private readonly CustomerOwnership $ownership) {}

    public function authorize(User $user): void
    {
        abort_unless($user->is_active && $user->can_access_erp && ($user->isAdministrator() || $user->hasPermission('preinvoices.create') || $user->hasPermission('preinvoices.own.view') || $this->ownership->canManage($user)), 403);
    }

    public function customer(User $user, int $id): Customer
    {
        $this->authorize($user);

        return $this->ownership->visible(Customer::query()->withBalance(), $user)->with(['assignment.seller:id,name', 'cityRelation:id,name'])->findOrFail($id);
    }

    public function listing(User $user, array $filters): Builder
    {
        $this->authorize($user);
        $base = $this->ownership->visible(Customer::query()->withBalance(), $user);
        if (($filters['status'] ?? 'active') !== 'all') {
            $base->where('is_active', ($filters['status'] ?? 'active') === 'active');
        }
        if (isset($filters['seller_id']) && $filters['seller_id'] !== '') {
            abort_unless($this->ownership->canSellAny($user), 403);
            (int) $filters['seller_id'] === 0 ? $base->whereDoesntHave('assignment') : $base->whereHas('assignment', fn ($q) => $q->where('seller_id', $filters['seller_id']));
        }
        if ($term = ProductFinderSearchNormalizer::normalize($filters['q'] ?? '')) {
            $base->where(function ($q) use ($term) {
                $normalize = fn ($column) => "REPLACE(REPLACE(REPLACE(COALESCE({$column},''),'ي','ی'),'ك','ک'),'‌',' ')";
                $full = DB::getDriverName() === 'sqlite' ? "COALESCE(first_name,'') || ' ' || COALESCE(last_name,'')" : "CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,''))";
                $q->whereRaw($normalize('name').' LIKE ?', ['%'.$term.'%'])->orWhereRaw($normalize($full).' LIKE ?', ['%'.$term.'%'])->orWhere('crm_customer_id', 'like', '%'.$term.'%');
                if (ctype_digit($term)) {
                    $q->orWhere('customers.id', (int) $term);
                }
                if (preg_match('/^[+\d\s()\-]+$/u', $term)) {
                    $phone = PhoneNormalizer::normalize($term);
                    if ($phone !== '') {
                        $q->orWhere('mobile', 'like', '%'.$phone.'%')->orWhereHas('phones', fn ($p) => $p->where('phone', 'like', '%'.$phone.'%'));
                    }
                }
            });
        }
        $query = Customer::query()->fromSub($base->toBase(), 'customers')->with(['assignment.seller:id,name', 'cityRelation:id,name']);
        $balance = 'COALESCE(opening_balance,0)+COALESCE(debit_sum,0)-COALESCE(credit_sum,0)';
        match ($filters['balance'] ?? 'all') {
            'debt' => $query->whereRaw("({$balance})>0"),'credit' => $query->whereRaw("({$balance})<0"),'settled' => $query->whereRaw("({$balance})=0"),default => null
        };
        if (($filters['sort'] ?? 'recent') === 'name') {
            $query->orderByRaw("COALESCE(NULLIF(name,''),last_name,first_name,'')");
        } elseif (($filters['sort'] ?? 'recent') === 'balance') {
            $query->orderByRaw("({$balance}) DESC");
        }

        // Registration order is available before the CRM activity history is imported.
        return $query->orderByDesc('id');
    }

    public function invoices(Customer $customer): Builder
    {
        return Invoice::where('customer_id', $customer->id)->orderByRaw('COALESCE(document_date,created_at) DESC')->orderByDesc('id');
    }

    public function returns(Customer $customer): Builder
    {
        return SalesReturnDocument::where('customer_id', $customer->id)->orderByRaw('COALESCE(applied_at,created_at) DESC')->orderByDesc('id');
    }

    public function ledger(Customer $customer): Builder
    {
        return app(AccountStatementPresentationService::class)->entries($customer)->orderByDesc('statement_date')->orderByDesc('created_at')->orderByDesc('id');
    }

    public function stats(Customer $customer): array
    {
        return [
            'invoices' => Invoice::where('customer_id', $customer->id)->count(),
            'preinvoices' => PreinvoiceOrder::where('customer_id', $customer->id)->withoutTemporaryAutosaves()->count(),
            'returns' => SalesReturnDocument::where('customer_id', $customer->id)->count(),
            'returned_amount' => (int) SalesReturnDocument::where('customer_id', $customer->id)->where('status', SalesReturnDocument::STATUS_APPLIED)->sum('total_refund_amount'),
            'last_invoice' => Invoice::where('customer_id', $customer->id)->whereNotIn('status', Invoice::cancelledStatuses())->selectRaw('MAX(COALESCE(document_date,created_at)) AS last_date')->value('last_date'),
        ];
    }

    public function ledgerMeta(Customer $customer, $rows): array
    {
        $ids = CustomerLedger::whereIn('id', $rows->pluck('id'))->effectiveForBalance()->pluck('id')->mapWithKeys(fn ($id) => [$id => true]);

        $models = [
            'invoices' => Invoice::class, 'payments' => \App\Models\InvoicePayment::class,
            'transfers' => \App\Models\WarehouseTransfer::class, 'salesReturnDocuments' => SalesReturnDocument::class,
            'personnelDocuments' => \App\Models\PersonnelAccountDocument::class,
            'financialDocuments' => \App\Models\FinancialAccountDocument::class,
        ];
        $references = [];
        foreach ($models as $key => $model) {
            $query = $model::query()->where('customer_id', $customer->id)->whereIn('id', $rows->where('reference_type', $model)->pluck('reference_id'));
            if ($key === 'payments') $query->with(['invoice', 'cheque']);
            if (in_array($key, ['financialDocuments', 'personnelDocuments'], true)) $query->with('creator');
            $references[$key] = $query->get()->keyBy('id');
        }
        $references['adjustmentDocuments'] = \App\Models\ActivityLog::where('subject_type', Customer::class)->where('subject_id', $customer->id)
            ->where('action', 'customer_balance_adjusted')->whereIn('id', $rows->where('reference_type', \App\Models\ActivityLog::class)->pluck('reference_id'))->get()->keyBy('id');
        return ['effectiveIds' => $ids, 'rowBalances' => app(AccountStatementPresentationService::class)->pageBalances($customer,$rows,$ids,$customer->balance), 'references' => $references];
    }
}
