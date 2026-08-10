@extends('layouts.app')

@section('content')
@php
    use Morilog\Jalali\Jalalian;
@endphp

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="page-title mb-0">تامین‌کننده‌ها</h4>
    <div class="d-flex gap-2">
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#supplierModal">
            افزودن تامین‌کننده
        </button>
        <a class="btn btn-outline-secondary" href="{{ route('purchases.create') }}">بازگشت به خرید جدید</a>
    </div>
</div>

{{-- Modal --}}
<div class="modal fade" id="supplierModal" tabindex="-1" aria-labelledby="supplierModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="supplierModalLabel">افزودن تامین‌کننده</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <form method="POST" action="{{ route('suppliers.store') }}">
                @csrf
                <div class="modal-body">
                    <div class="row g-3">

                        <div class="col-md-4">
                            <label class="form-label">نام تامین‌کننده</label>
                            <input name="name"
                                   class="form-control @error('name') is-invalid @enderror"
                                   value="{{ old('name') }}" required>
                            @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">شماره تماس</label>
                            <input name="phone"
                                   class="form-control @error('phone') is-invalid @enderror"
                                   value="{{ old('phone') }}" required>
                            @error('phone') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">کد پستی (اختیاری)</label>
                            <input name="postal_code"
                                   class="form-control @error('postal_code') is-invalid @enderror"
                                   value="{{ old('postal_code') }}">
                            @error('postal_code') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">استان (اختیاری)</label>
                            <select name="province_id"
                                    id="supplier_province_id"
                                    class="form-select @error('province_id') is-invalid @enderror">
                                <option value=""></option>
                            </select>
                            @error('province_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">شهر (اختیاری)</label>
                            <select name="city_id"
                                    id="supplier_city_id"
                                    class="form-select @error('city_id') is-invalid @enderror">
                                <option value=""></option>
                            </select>
                            @error('city_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="col-md-12">
                            <label class="form-label">آدرس (اختیاری)</label>
                            <input name="address"
                                   class="form-control @error('address') is-invalid @enderror"
                                   value="{{ old('address') }}">
                            @error('address') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="col-md-12">
                            <label class="form-label">توضیحات اضافی (اختیاری)</label>
                            <textarea name="additional_notes"
                                      class="form-control @error('additional_notes') is-invalid @enderror"
                                      rows="3">{{ old('additional_notes') }}</textarea>
                            @error('additional_notes') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">بستن</button>
                    <button class="btn btn-primary">ثبت</button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- List --}}
<div class="card">
    <div class="card-body">
        <table class="table table-striped mb-0">
            <thead>
                <tr>
                    <th>نام</th>
                    <th>شماره تماس</th>
                    <th>استان/شهر</th>
                    <th>آدرس</th>
                    <th>کد پستی</th>
                    <th>توضیحات اضافی</th>
                    <th>تاریخ ثبت</th>
                </tr>
            </thead>
            <tbody>
                @forelse($suppliers as $supplier)
                    <tr>
                        <td>{{ $supplier->name }}</td>
                        <td>{{ $supplier->phone ?: '-' }}</td>
                        <td>
                            @if($supplier->province || $supplier->city)
                                {{ $supplier->province ?: '-' }} / {{ $supplier->city ?: '-' }}
                            @else
                                -
                            @endif
                        </td>
                        <td>{{ $supplier->address ?: '-' }}</td>
                        <td>{{ $supplier->postal_code ?: '-' }}</td>
                        <td>{{ $supplier->additional_notes ?: '-' }}</td>
                        <td>{{ $supplier->created_at ? Jalalian::fromDateTime($supplier->created_at)->format('Y/m/d') : '-' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center text-muted py-4">تامین‌کننده‌ای ثبت نشده است.</td></tr>
                @endforelse
            </tbody>
        </table>

        <div class="mt-3">{{ $suppliers->links() }}</div>
    </div>
</div>

{{-- Auto-open modal if validation errors exist --}}
@if ($errors->any())
<script>
document.addEventListener('DOMContentLoaded', function () {
    var modal = new bootstrap.Modal(document.getElementById('supplierModal'));
    modal.show();
});
</script>
@endif

@push('scripts')
<script>
const SUPPLIER_AREA_API = "{{ url('/preinvoice/api/area') }}";

function supplierInitSelect2(selectEl, placeholder) {
    if (!window.jQuery || !window.jQuery.fn?.select2) return;
    const $el = $(selectEl);
    if ($el.hasClass('select2-hidden-accessible')) {
        $el.off('select2:select select2:clear');
        $el.select2('destroy');
    }
    $el.select2({ width:'100%', dir:'rtl', placeholder, allowClear:true, dropdownParent: $el.closest('.modal') });
    $el.on('select2:select select2:clear', function(){ this.dispatchEvent(new Event('change',{bubbles:true})); });
}

document.addEventListener('DOMContentLoaded', async function () {
    const provinceSelect = document.getElementById('supplier_province_id');
    const citySelect = document.getElementById('supplier_city_id');
    const oldProvinceId = {{ old('province_id') ? (int) old('province_id') : 'null' }};
    const oldCityId = {{ old('city_id') ? (int) old('city_id') : 'null' }};
    if (!provinceSelect || !citySelect) return;

    let provinces = [];
    try {
        const res = await fetch(SUPPLIER_AREA_API, { headers: { 'Accept': 'application/json' } });
        const json = await res.json();
        provinces = json?.data?.provinces ?? [];
    } catch (e) {
        provinces = [];
    }

    provinceSelect.innerHTML = '<option value=""></option>';
    provinces.forEach((province) => {
        const option = document.createElement('option');
        option.value = province.id;
        option.textContent = province.name;
        if (oldProvinceId && Number(option.value) === Number(oldProvinceId)) {
            option.selected = true;
        }
        provinceSelect.appendChild(option);
    });

    const setCityOptions = (provinceId, selectedCityId = null) => {
        const province = provinces.find((p) => Number(p.id) === Number(provinceId));
        const cities = province?.cities ?? [];

        citySelect.innerHTML = '<option value=""></option>';
        cities.forEach((city) => {
            const option = document.createElement('option');
            option.value = city.id;
            option.textContent = city.name;
            if (selectedCityId && Number(selectedCityId) === Number(city.id)) option.selected = true;
            citySelect.appendChild(option);
        });
        citySelect.disabled = cities.length === 0;
        if (window.jQuery) $(citySelect).trigger('change.select2');
    };

    provinceSelect.addEventListener('change', function () {
        setCityOptions(this.value, null);
    });

    setCityOptions(provinceSelect.value, oldCityId);

    supplierInitSelect2(provinceSelect, 'انتخاب استان...');
    supplierInitSelect2(citySelect, 'انتخاب شهر...');
});
</script>
@endpush

@endsection
