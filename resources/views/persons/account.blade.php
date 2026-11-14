@extends('layouts.app')

@section('title', 'گردش حساب '.$person['name'])

@section('content')
@php
    $rial = fn ($value) => \App\Support\Currency::formatRial(abs((int) $value));
    $balanceText = fn ($value) => $value > 0 ? 'بدهکار' : ($value < 0 ? 'بستانکار' : 'تسویه');
    $balanceClass = fn ($value) => $value > 0 ? 'text-danger' : ($value < 0 ? 'text-success' : 'text-muted');
@endphp

<div class="container-fluid py-3" dir="rtl">
    @if($person['link_warning'] ?? null)<div class="alert alert-warning">{{ $person['link_warning'] }}</div>@endif
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
        <div>
            <a href="{{ route('persons.index') }}" class="text-decoration-none small">← بازگشت به اشخاص</a>
            <h1 class="h4 mt-2 mb-1">گردش حساب {{ $person['name'] ?: 'شخص' }}</h1>
            <div class="d-flex flex-wrap align-items-center gap-2 text-muted small">
                <span dir="ltr">{{ $person['mobile'] ?: 'بدون شماره تماس' }}</span>
                @if($person['is_customer']) <span class="badge text-bg-primary">مشتری</span> @endif
                @if($person['is_supplier']) <span class="badge text-bg-success">تأمین‌کننده</span> @endif
            </div>
        </div>
        <div class="text-muted small">مبالغ به ریال · مثبت: شخص بدهکار است · منفی: شخص بستانکار است</div>
    </div>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    @if($person['is_supplier'] && \App\Support\PageAccessCatalog::userCanRoute(auth()->user(), 'persons.supplier-transactions.store'))
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white"><strong>ثبت پرداخت یا دریافت از تأمین‌کننده</strong></div>
            <div class="card-body">
                <form method="POST" action="{{ route('persons.supplier-transactions.store', request()->route('personKey')) }}" class="row g-2 align-items-end">
                    @csrf
                    <input type="hidden" name="submission_token" value="{{ $supplierSubmissionToken }}">
                    <div class="col-12 col-md-2">
                        <label class="form-label">نوع تراکنش</label>
                        <select name="direction" class="form-select" required>
                            <option value="payment">پرداخت به شخص</option>
                            <option value="receipt">دریافت از شخص</option>
                        </select>
                    </div>
                    <div class="col-12 col-md-2">
                        <label class="form-label">مبلغ (ریال)</label>
                        <input type="number" name="amount" min="1" max="9000000000000000" class="form-control" required>
                    </div>
                    <div class="col-12 col-md-2">
                        <label class="form-label">تاریخ</label>
                        <input type="datetime-local" name="transaction_date" class="form-control" value="{{ now()->format('Y-m-d\\TH:i') }}" required>
                    </div>
                    <div class="col-12 col-md-2">
                        <label class="form-label">روش پرداخت</label>
                        <input type="text" name="method" maxlength="50" class="form-control" placeholder="نقدی / کارت / حواله">
                    </div>
                    <div class="col-12 col-md-2">
                        <label class="form-label">شماره پیگیری</label>
                        <input type="text" name="reference" maxlength="100" class="form-control">
                    </div>
                    <div class="col-12 col-md-2">
                        <label class="form-label">شرح</label>
                        <input type="text" name="note" maxlength="255" class="form-control">
                    </div>
                    <div class="col-12 d-flex justify-content-end">
                        <button type="submit" class="btn btn-primary">ثبت در گردش حساب</button>
                    </div>
                </form>
                @if($errors->any())
                    <div class="text-danger small mt-2">{{ $errors->first() }}</div>
                @endif
            </div>
        </div>
    @endif

    <div class="row g-3 mb-3">
        <div class="col-12 col-md-6 col-xl-3">
            <div class="card h-100 border-0 shadow-sm"><div class="card-body">
                <div class="small text-muted mb-2">مانده افتتاحیه</div>
                <div class="fs-5 fw-semibold">{{ $rial($openingBalance) }}</div>
                <div class="small {{ $balanceClass($openingBalance) }}">{{ $balanceText($openingBalance) }}</div>
            </div></div>
        </div>
        <div class="col-12 col-md-6 col-xl-3">
            <div class="card h-100 border-0 shadow-sm"><div class="card-body">
                <div class="small text-muted mb-2">جمع بدهکار</div>
                <div class="fs-5 fw-semibold text-danger">{{ $rial($totalDebit) }}</div>
                <div class="small text-muted">اسناد مؤثر در مانده</div>
            </div></div>
        </div>
        <div class="col-12 col-md-6 col-xl-3">
            <div class="card h-100 border-0 shadow-sm"><div class="card-body">
                <div class="small text-muted mb-2">جمع بستانکار</div>
                <div class="fs-5 fw-semibold text-success">{{ $rial($totalCredit) }}</div>
                <div class="small text-muted">اسناد مؤثر در مانده</div>
            </div></div>
        </div>
        <div class="col-12 col-md-6 col-xl-3">
            <div class="card h-100 border-0 shadow-sm"><div class="card-body">
                <div class="small text-muted mb-2">مانده فعلی حساب</div>
                <div class="fs-5 fw-semibold {{ $balanceClass($netBalance) }}">{{ $rial($netBalance) }}</div>
                <div class="small {{ $balanceClass($netBalance) }}">{{ $balanceText($netBalance) }}</div>
            </div></div>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2 py-3">
            <strong>دفتر گردش حساب</strong>
            <span class="small text-muted">{{ number_format($ledgers->total()) }} ردیف · تاریخ سند، جدیدترین ابتدا</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>تاریخ سند</th>
                        <th>شماره سند</th>
                        <th>شرح</th>
                        <th class="text-nowrap">بدهکار</th>
                        <th class="text-nowrap">بستانکار</th>
                        <th class="text-nowrap">مانده پس از سند</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($ledgers as $ledger)
                        @php
                            $source = $sources[$ledger->ledger_key] ?? [];
                            $rowBalance = $ledgerBalances[$ledger->ledger_key] ?? $netBalance;
                            $description = $source['description'] ?? ($ledger->note ?: ($ledger->type === 'debit' ? 'ثبت بدهکار' : 'ثبت بستانکار'));
                            $date = $ledger->statement_date && $ledger->statement_date !== '1970-01-01'
                                ? \Morilog\Jalali\Jalalian::fromDateTime($ledger->statement_date)->format('Y/m/d')
                                : '—';
                        @endphp
                        <tr class="{{ (int) $ledger->is_effective !== 1 ? 'text-muted' : '' }}">
                            <td class="text-nowrap">{{ $date }}</td>
                            <td class="text-nowrap">
                                @if(!empty($source['url']))
                                    <a href="{{ $source['url'] }}">{{ $source['label'] ?? 'GL-'.$ledger->id }}</a>
                                @else
                                    {{ $source['label'] ?? 'GL-'.$ledger->id }}
                                @endif
                            </td>
                            <td>
                                <div>{{ $description }}</div>
                                @if($ledger->note && $ledger->note !== $description)
                                    <small class="text-muted">{{ $ledger->note }}</small>
                                @endif
                                @if((int) $ledger->is_effective !== 1)
                                    <div><span class="badge text-bg-secondary mt-1">بدون اثر در مانده</span></div>
                                @endif
                            </td>
                            <td class="text-nowrap">{{ $ledger->type === 'debit' ? $rial($ledger->amount) : '—' }}</td>
                            <td class="text-nowrap">{{ $ledger->type === 'credit' ? $rial($ledger->amount) : '—' }}</td>
                            <td class="text-nowrap {{ $balanceClass($rowBalance) }}">
                                <strong>{{ $rial($rowBalance) }}</strong>
                                <small class="d-block">{{ $balanceText($rowBalance) }}</small>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-muted py-5">گردشی برای این شخص ثبت نشده است.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
            <span class="small text-muted">{{ number_format($ledgers->total()) }} ثبت</span>
            {{ $ledgers->links() }}
        </div>
    </div>
</div>
@endsection
