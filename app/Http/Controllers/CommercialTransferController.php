<?php

namespace App\Http\Controllers;

use App\Models\CommercialTransferDocument;
use App\Models\Customer;
use App\Models\CustomerAssignment;
use App\Models\Invoice;
use App\Models\User;
use App\Services\CommercialTransferService;
use App\Services\Personnel\PersonnelDates;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CommercialTransferController extends Controller
{
    public function __construct(private readonly CommercialTransferService $transfers) {}

    private function kinds($user): array
    {
        return array_values(array_filter(['customer', 'invoice'], fn ($kind) => $this->transfers->allowed($user, $kind)));
    }

    public function index(Request $request)
    {
        $kinds = $this->kinds($request->user());
        abort_unless($kinds, 403);
        $data = $request->validate(['kind' => 'nullable|in:customer,invoice', 'status' => 'nullable|in:draft,registered,cancelled', 'q' => 'nullable|string|max:100']);
        $base = CommercialTransferDocument::whereIn('kind', $kinds)->where('status', '!=', 'deleted');
        $summary = ['all' => (clone $base)->count(), 'registered' => (clone $base)->where('status', 'registered')->count(), 'draft' => (clone $base)->where('status', 'draft')->count()];
        if ($request->filled('kind')) {
            $base->where('kind', $data['kind']);
        }if ($request->filled('status')) {
            $base->where('status', $data['status']);
        }
        if ($q = trim($data['q'] ?? '')) {
            $q = PersonnelDates::digits($q);
            $base->where(fn ($b) => $b->where('number', 'like', "%{$q}%")->orWhere('from_name', 'like', "%{$q}%")->orWhere('to_name', 'like', "%{$q}%")->orWhereHas('items', fn ($i) => $i->where('label', 'like', "%{$q}%")));
        }
        $documents = $base->withCount('items')->latest('number')->paginate(10)->withQueryString();
        $sellers = User::query()->activeErpUsers()->orderBy('name')->get(['id', 'name', 'is_active', 'can_access_erp']);
        $sourceSellers = User::query()->orderBy('name')->get(['id', 'name', 'is_active', 'can_access_erp']);

        return response()->view($request->ajax() ? 'commercial.transfers.list' : 'commercial.transfers.index', compact('documents', 'summary', 'sellers', 'sourceSellers', 'kinds'))->header('Cache-Control', 'private, no-store');
    }

    public function search(Request $request)
    {
        $data = $request->validate(['kind' => 'required|in:customer,invoice', 'from_id' => 'nullable|integer|min:0', 'q' => 'nullable|string|max:100', 'page' => 'nullable|integer|min:1']);
        $kind = $data['kind'];
        $this->transfers->authorize($request->user(), $kind);
        $from = (int) ($data['from_id'] ?? 0);
        $this->transfers->source($from);
        if ($kind === 'customer') {
            $query = Customer::with('assignment.seller');
            $from ? $query->whereHas('assignment', fn ($q) => $q->where('seller_id', $from)) : $query->whereDoesntHave('assignment');
        } else {
            $query = Invoice::query()->leftJoin('preinvoice_orders', 'preinvoice_orders.id', '=', 'invoices.preinvoice_order_id')->select('invoices.*')->with(['customer', 'preinvoiceOrder']);
            $query->whereRaw($from ? Invoice::effectiveSellerSql().' = ?' : Invoice::effectiveSellerSql().' IS NULL', $from ? [$from] : []);
            $query->whereNull('invoices.cancelled_at');
        }
        if ($q = trim($data['q'] ?? '')) {
            $q = PersonnelDates::digits($q);
            $query->where(function ($b) use ($kind, $q) {
                if ($kind === 'customer') {
                    $full = DB::connection()->getDriverName() === 'sqlite' ? "TRIM(COALESCE(first_name,'') || ' ' || COALESCE(last_name,''))" : "TRIM(CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,'')))";
                    $b->where('name', 'like', "%{$q}%")->orWhereRaw("{$full} LIKE ?", ["%{$q}%"])->orWhere('mobile', 'like', "%{$q}%")->orWhere('crm_customer_id', 'like', "%{$q}%");
                } else {
                    $b->where('invoices.uuid', 'like', "%{$q}%")->orWhere('invoices.customer_name', 'like', "%{$q}%")->orWhere('invoices.customer_mobile', 'like', "%{$q}%");
                }
            });
        }
        $rows = $query->orderByDesc($kind === 'invoice' ? 'invoices.id' : 'id')->paginate(20);
        $items = $rows->map(function ($target) use ($kind) {
            $snapshot = $this->transfers->snapshot($kind, $target);

            return ['id' => $target->id, 'label' => $this->transfers->label($kind, $target), 'detail' => $kind === 'customer' ? ($target->mobile.' · کد '.($target->crm_customer_id ?: $target->id)) : ('مبلغ '.number_format($target->total).' ریال'), 'token' => $this->transfers->token($kind, $target->id, $snapshot)];
        });

        $emptyReason = null;
        $emptyMessage = null;
        if ($rows->total() === 0) {
            if ($kind === 'customer' && $from && ! CustomerAssignment::query()->exists()) {
                $emptyReason = 'ownership_not_initialized';
                $emptyMessage = 'مسئول مشتریان در لوکال هنوز تعیین یا از CRM وارد نشده است. برای تخصیص اولیه، مشتریان «بدون مسئول» را انتخاب کنید.';
            } elseif (trim($data['q'] ?? '') !== '') {
                $emptyReason = 'search_no_matches';
                $emptyMessage = 'نتیجه‌ای برای این جستجو در موارد همکار انتخاب‌شده پیدا نشد.';
            } else {
                $emptyReason = $kind === 'customer' ? 'no_assigned_customers' : 'no_invoices';
                $emptyMessage = $kind === 'customer' ? 'مشتری‌ای به این همکار تخصیص داده نشده است.' : 'فاکتور قابل انتقالی برای این همکار پیدا نشد.';
            }
        }

        return response()->json(['data' => $items, 'page' => $rows->currentPage(), 'pages' => $rows->lastPage(), 'total' => $rows->total(), 'empty_reason' => $emptyReason, 'empty_message' => $emptyMessage, 'can_choose_unassigned' => $rows->total() === 0 && $kind === 'customer' && $from > 0])->header('Cache-Control', 'private, no-store');
    }

    private function validated(Request $r, bool $updating = false): array
    {
        return $r->validate(['submission_key' => [$updating ? 'nullable' : 'required', 'uuid'], 'version' => [$updating ? 'required' : 'nullable', 'integer', 'min:1'], 'kind' => 'required|in:customer,invoice', 'from_id' => 'nullable|integer|min:0', 'to_id' => 'required|integer|exists:users,id', 'reason' => 'required|string|max:1000', 'action' => 'required|in:draft,register', 'items' => 'required|array|min:1|max:100', 'items.*.id' => 'required|integer|distinct|min:1', 'items.*.token' => 'required|string|max:64']);
    }

    private function result($doc, int $status = 200)
    {
        $message = match ($doc->status) {
            'registered' => 'انتقال ثبت و اجرا شد.',
            'draft' => 'پیش‌نویس ذخیره شد؛ انتقال اجرا نشده است.',
            'cancelled' => 'این درخواست قبلاً ثبت و سپس لغو شده است.',
            default => 'این پیش‌نویس قبلاً حذف شده است.',
        };

        return response()->json(['ok' => true, 'id' => $doc->id, 'number' => $doc->number, 'version' => $doc->version, 'status' => $doc->status, 'message' => $message], $status);
    }

    public function store(Request $r)
    {
        $this->authorizeAny($r);
        $data = $this->validated($r);
        [$doc,$created] = $this->transfers->save($data, $r->user());

        return $this->result($doc, $created ? 201 : 200);
    }

    private function authorizeAny(Request $r): void
    {
        abort_unless($this->kinds($r->user()), 403);
    }

    public function update(Request $r, CommercialTransferDocument $document)
    {
        $this->transfers->authorize($r->user(), $document->kind);
        $data = $this->validated($r, true);
        abort_unless($data['kind'] === $document->kind, 422, 'نوع سند قابل تغییر نیست.');
        [$doc] = $this->transfers->save($data, $r->user(), $document);

        return $this->result($doc);
    }

    public function show(Request $r, CommercialTransferDocument $document)
    {
        $this->transfers->authorize($r->user(), $document->kind);
        abort_if($document->status === 'deleted', 404);
        $document->load(['items', 'events']);
        if ($r->query('format') === 'json') {
            return response()->json(['document' => $document])->header('Cache-Control', 'private, no-store');
        }

        return response()->view($r->boolean('print') ? 'commercial.transfers.print' : 'commercial.transfers.detail', compact('document'))->header('Cache-Control', 'private, no-store');
    }

    public function register(Request $r, CommercialTransferDocument $document)
    {
        $this->transfers->authorize($r->user(), $document->kind);
        $data = $r->validate(['version' => 'required|integer|min:1']);

        return $this->result($this->transfers->register($document, $r->user(), $data['version']));
    }

    public function cancel(Request $r, CommercialTransferDocument $document)
    {
        $this->transfers->authorize($r->user(), $document->kind);
        $data = $r->validate(['version' => 'required|integer|min:1', 'reason' => 'required|string|max:1000']);
        $this->transfers->cancel($document, $r->user(), $data['version'], trim($data['reason']));

        return response()->json(['ok' => true, 'message' => 'انتقال لغو شد؛ سابقه سند حفظ شد.']);
    }

    public function destroy(Request $r, CommercialTransferDocument $document)
    {
        $this->transfers->authorize($r->user(), $document->kind);
        $data = $r->validate(['version' => 'required|integer|min:1']);
        $this->transfers->remove($document, $r->user(), $data['version']);

        return response()->json(['ok' => true, 'message' => 'پیش‌نویس حذف شد.']);
    }
}
