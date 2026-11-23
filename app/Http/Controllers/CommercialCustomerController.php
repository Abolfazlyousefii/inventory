<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\User;
use App\Services\CustomerOwnership;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CommercialCustomerController extends Controller
{
    public function __construct(private readonly CustomerOwnership $ownership) {}

    private function access(Request $request): void
    {
        $u = $request->user();
        abort_unless($u->is_active && $u->can_access_erp && ($u->isAdministrator() || $u->hasPermission('preinvoices.create') || $u->hasPermission('preinvoices.own.view') || $this->ownership->canManage($u)), 403);
    }

    public function index(Request $request)
    {
        $this->access($request);

        return $this->listing($request, false);
    }

    public function transfers(Request $request)
    {
        abort_unless($this->ownership->canManage($request->user()), 403);

        return redirect()->route('commercial.transfers.index', ['kind'=>'customer']);
    }

    private function listing(Request $request, bool $transfers)
    {
        $data = $request->validate(['q' => 'nullable|string|max:100', 'seller_id' => 'nullable|integer', 'unassigned' => 'nullable|boolean']);
        $query = Customer::query()->with('assignment.seller:id,name');
        if (! $transfers) {
            $this->ownership->visible($query, $request->user());
        }
        if ($transfers && $request->boolean('unassigned')) {
            $query->whereDoesntHave('assignment');
        }
        if ($transfers && $request->filled('seller_id')) {
            $query->whereHas('assignment', fn ($q) => $q->where('seller_id', $data['seller_id']));
        }
        if ($term = trim($data['q'] ?? '')) {
            $term = strtr($term, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']);
            $fullName = DB::connection()->getDriverName() === 'sqlite'
                ? "TRIM(COALESCE(first_name, '') || ' ' || COALESCE(last_name, ''))"
                : "TRIM(CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, '')))";
            $query->where(fn ($q) => $q->where('name', 'like', "%{$term}%")->orWhereRaw("{$fullName} LIKE ?", ["%{$term}%"])->orWhere('mobile', 'like', "%{$term}%")->orWhere('crm_customer_id', 'like', "%{$term}%"));
        }
        $customers = $query->orderByDesc('id')->paginate(20)->withQueryString();
        $sellers = $transfers ? User::where('is_active', true)->where('can_access_erp', true)->orderBy('name')->get(['id', 'name']) : collect();

        return response()->view($request->ajax() ? 'commercial.customers.list' : 'commercial.customers.index', compact('customers', 'sellers', 'transfers'))->header('Cache-Control', 'private, no-store');
    }

    public function transfer(Request $request, Customer $customer)
    {
        abort_unless($this->ownership->canManage($request->user()), 403);
        $data = $request->validate(['seller_id' => 'required|integer|exists:users,id', 'version' => 'required|integer|min:0', 'reason' => 'required|string|max:1000']);
        $this->ownership->transfer($customer, User::findOrFail($data['seller_id']), $request->user(), (int) $data['version'], $data['reason']);

        return response()->json(['ok' => true, 'message' => 'مسئول مشتری تغییر کرد؛ فاکتورهای قبلی حفظ شدند.']);
    }
}
