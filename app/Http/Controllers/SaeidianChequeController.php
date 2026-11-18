<?php

namespace App\Http\Controllers;

use App\Models\Cheque;
use App\Models\SaeidianChequeEntry;
use App\Services\ChequeCoverage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Morilog\Jalali\Jalalian;

class SaeidianChequeController extends Controller
{
    private function authorizeFinance(Request $r): void
    {
        abort_unless($r->user() && ($r->user()->hasAnyRole(['admin', 'finance']) || $r->user()->can('finance.approve')), 403);
    }

    private function date(string $value): string
    {
        $value = strtr($value, array_combine(preg_split('//u', '۰۱۲۳۴۵۶۷۸۹', -1, PREG_SPLIT_NO_EMPTY), str_split('0123456789')));
        try {
            $j = Jalalian::fromFormat('Y/m/d', $value);
            if ($j->format('Y/m/d') !== $value) {
                throw new \Exception;
            }

return $j->toCarbon()->toDateString();
        } catch (\Throwable $e) {
            throw ValidationException::withMessages(['effective_date' => 'تاریخ معتبر شمسی با قالب ۱۴۰۵/۰۸/۲۴ وارد کنید.']);
        }
    }

    public function index(Request $r, ChequeCoverage $coverage)
    {
        $this->authorizeFinance($r);
        $month = $r->input('month', Jalalian::now()->format('Y/m'));
        if (! preg_match('~^1[34][0-9]{2}/(0[1-9]|1[0-2])$~', $month)) {
            abort(422);
        }
        $start = Jalalian::fromFormat('Y/m/d', $month.'/01');
        $end = $start->addMonths(1);
        $from = $start->toCarbon()->toDateString();
        $to = $end->toCarbon()->subDay()->toDateString();
        $entries = SaeidianChequeEntry::whereDate('effective_date', '<=', $to)->orderBy('effective_date')->orderBy('id')->get();
        $monthly = $entries->filter(fn ($e) => $e->effective_date->toDateString() >= $from);
        $summary = $coverage->balance($entries, $to);
        $opening = $coverage->balance($entries, $start->toCarbon()->subDay()->toDateString());
        $search = trim((string) $r->input('q', ''));
        $cheques = Cheque::query()->where('amount', '>', 0)->whereNotIn('id', SaeidianChequeEntry::whereNotNull('cheque_id')->select('cheque_id'))
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->where('customer_name', 'like', '%'.$search.'%')->orWhere('cheque_number', 'like', '%'.$search.'%')->orWhere('amount', $search)))->orderBy('due_date')->limit(40)->get();

        return view('finance.saeidian-cheques', compact('start', 'end', 'month', 'entries', 'monthly', 'summary', 'opening', 'cheques', 'search', 'coverage'));
    }

    public function store(Request $r)
    {
        $this->authorizeFinance($r);
        $d = $r->validate(['kind' => 'required|in:issued,deposit', 'deposited_confirmation' => 'accepted_if:kind,deposit', 'cheque_id' => 'required_if:kind,deposit|nullable|integer|exists:cheques,id', 'amount' => 'required_if:kind,issued|nullable|integer|min:1|max:999999999999999', 'effective_date' => 'required|string|max:10', 'cheque_number' => 'required_if:kind,issued|nullable|string|max:255', 'party' => 'required_if:kind,issued|nullable|string|max:255', 'notes' => 'nullable|string|max:2000']);
        $date = $this->date($d['effective_date']);
        DB::transaction(function () use ($d, $date, $r) {
            if ($d['kind'] === 'deposit') {
                $c = Cheque::lockForUpdate()->findOrFail($d['cheque_id']);
                if (SaeidianChequeEntry::where('cheque_id', $c->id)->exists()) {
                    throw ValidationException::withMessages(['cheque_id' => 'این چک قبلاً در حساب ثبت شده است.']);
                }
                if ($c->amount <= 0) {
                    throw ValidationException::withMessages(['cheque_id' => 'مبلغ چک معتبر نیست.']);
                }
                $d['amount'] = $c->amount;
                $d['party'] = $c->customer_name ?: 'مشتری';
                $d['cheque_number'] = $c->cheque_number;
            } else {
                $d['cheque_id'] = null;
            }
            unset($d['deposited_confirmation']);
            SaeidianChequeEntry::create(array_merge($d, ['effective_date' => $date, 'created_by' => $r->user()->id]));
        });

        return back()->with('success','چک در تقویم سعیدیان ثبت شد؛ گردش مالی مشتری تغییر نکرد.');
    }
}
