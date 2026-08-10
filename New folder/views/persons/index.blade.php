@extends('layouts.app')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="mb-0">👥 اشخاص</h4>
        <div class="text-muted small">مدیریت یکپارچه مشتریان و تامین‌کنندگان</div>
    </div>
    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#personModal">
        ➕ ساخت شخص
    </button>
</div>

<div class="card mb-3">
    <div class="card-body">
        <form class="d-flex gap-2" method="GET" action="{{ route('persons.index') }}">
            <input class="form-control" name="q" value="{{ $q ?? '' }}" placeholder="جستجو نام/شماره تماس">
            <button class="btn btn-outline-secondary">جستجو</button>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table align-middle">
                <thead>
                    <tr>
                        <th>نام</th>
                        <th>شماره تماس</th>
                        <th>نقش</th>
                        <th>آدرس</th>
                        <th>کد پستی</th>
                        <th>توضیحات</th>
                        <th>عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($people as $person)
                        <tr>
                            <td>{{ $person['name'] ?: '-' }}</td>
                            <td>{{ $person['mobile'] ?: '-' }}</td>
                            <td>
                                @if($person['is_customer'])
                                    <span class="badge text-bg-primary">مشتری</span>
                                @endif
                                @if($person['is_supplier'])
                                    <span class="badge text-bg-success">تامین‌کننده</span>
                                @endif
                            </td>
                            <td>{{ $person['address'] ?: '-' }}</td>
                            <td>{{ $person['postal_code'] ?: '-' }}</td>
                            <td>{{ $person['description'] ?: '-' }}</td>
                            <td>
                                <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editPersonModal{{ $loop->iteration }}">ویرایش</button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-muted py-4">شخصی یافت نشد.</td></tr>
                    @endforelse

                </tbody>
            </table>
        </div>

        {{ $people->links() }}
    </div>
</div>



@foreach($people as $person)
<div class="modal fade person-edit-modal" id="editPersonModal{{ $loop->iteration }}" tabindex="-1" aria-hidden="true" data-province-id="{{ $person['province_id'] ?? '' }}" data-city-id="{{ $person['city_id'] ?? '' }}">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <form class="modal-content" method="POST" action="{{ route('persons.update', $person['key']) }}">
            @csrf
            @method('PUT')
            <div class="modal-header">
                <h5 class="modal-title">✏️ ویرایش شخص</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-6"><label class="form-label">نام شخص *</label><input name="name" class="form-control" value="{{ $person['name'] }}" required></div>
                    <div class="col-md-6"><label class="form-label">شماره تماس *</label><input name="mobile" class="form-control" value="{{ $person['mobile'] }}" required></div>
                    <div class="col-md-12">
                        <label class="form-label d-block mb-2">نوع شخص *</label>
                        <div class="d-flex gap-4">
                            <div class="form-check"><input class="form-check-input" type="checkbox" name="types[]" value="customer" id="edit_customer_{{ $loop->iteration }}" @checked($person['is_customer'])><label class="form-check-label" for="edit_customer_{{ $loop->iteration }}">مشتری</label></div>
                            <div class="form-check"><input class="form-check-input" type="checkbox" name="types[]" value="supplier" id="edit_supplier_{{ $loop->iteration }}" @checked($person['is_supplier'])><label class="form-check-label" for="edit_supplier_{{ $loop->iteration }}">تامین‌کننده</label></div>
                        </div>
                    </div>
                    <div class="col-md-4"><label class="form-label">استان</label><select name="province_id" class="form-select person-province-select"><option value=""></option></select></div>
                    <div class="col-md-4"><label class="form-label">شهر</label><select name="city_id" class="form-select person-city-select"><option value=""></option></select></div>
                    <div class="col-md-4"><label class="form-label">کد پستی</label><input name="postal_code" class="form-control" value="{{ $person['postal_code'] }}"></div>
                    <div class="col-md-12"><label class="form-label">آدرس</label><input name="address" class="form-control" value="{{ $person['address'] }}"></div>
                    <div class="col-md-12"><label class="form-label">توضیحات</label><textarea name="description" rows="3" class="form-control">{{ $person['description'] }}</textarea></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">بستن</button>
                <button class="btn btn-primary">ذخیره تغییرات</button>
            </div>
        </form>
    </div>
</div>
@endforeach

<div class="modal fade" id="personModal" tabindex="-1" aria-labelledby="personModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <form class="modal-content" method="POST" action="{{ route('persons.store') }}">
            @csrf
            <div class="modal-header">
                <h5 class="modal-title" id="personModalLabel">➕ ساخت شخص جدید</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">نام شخص *</label>
                        <input name="name" class="form-control @error('name') is-invalid @enderror" value="{{ old('name') }}" required>
                        @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">شماره تماس *</label>
                        <input name="mobile" class="form-control @error('mobile') is-invalid @enderror" value="{{ old('mobile') }}" required>
                        @error('mobile') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <div class="col-md-12">
                        <label class="form-label d-block mb-2">نوع شخص *</label>
                        <div class="d-flex gap-4">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="types[]" value="customer" id="type_customer" @checked(collect(old('types', []))->contains('customer'))>
                                <label class="form-check-label" for="type_customer">مشتری</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="types[]" value="supplier" id="type_supplier" @checked(collect(old('types', []))->contains('supplier'))>
                                <label class="form-check-label" for="type_supplier">تامین‌کننده</label>
                            </div>
                        </div>
                        @error('types') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">استان</label>
                        <select name="province_id" id="person_province_id" class="form-select @error('province_id') is-invalid @enderror">
                            <option value=""></option>
                        </select>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">شهر</label>
                        <select name="city_id" id="person_city_id" class="form-select @error('city_id') is-invalid @enderror">
                            <option value=""></option>
                        </select>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">کد پستی</label>
                        <input name="postal_code" class="form-control @error('postal_code') is-invalid @enderror" value="{{ old('postal_code') }}">
                    </div>

                    <div class="col-md-12">
                        <label class="form-label">آدرس</label>
                        <input name="address" class="form-control @error('address') is-invalid @enderror" value="{{ old('address') }}">
                    </div>

                    <div class="col-md-12">
                        <label class="form-label">توضیحات</label>
                        <textarea name="description" rows="3" class="form-control @error('description') is-invalid @enderror">{{ old('description') }}</textarea>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">بستن</button>
                <button class="btn btn-primary">ثبت شخص</button>
            </div>
        </form>
    </div>
</div>

@if ($errors->any())
<script>
document.addEventListener('DOMContentLoaded', function () {
    (new bootstrap.Modal(document.getElementById('personModal'))).show();
});
</script>
@endif

@push('scripts')
<script>
const PERSON_AREA_API = "{{ url('/preinvoice/api/area') }}";

function personInitSelect2(selectEl, placeholder) {
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
    let provinces = [];
    try {
        const res = await fetch(PERSON_AREA_API, { headers: { 'Accept': 'application/json' } });
        const json = await res.json();
        provinces = json?.data?.provinces ?? [];
    } catch (e) {
        provinces = [];
    }

    const fillProvinceOptions = (provinceSelect, selectedProvinceId = null) => {
        provinceSelect.innerHTML = '<option value=""></option>';
        provinces.forEach((province) => {
            const option = document.createElement('option');
            option.value = province.id;
            option.textContent = province.name;
            if (selectedProvinceId && Number(option.value) === Number(selectedProvinceId)) option.selected = true;
            provinceSelect.appendChild(option);
        });
    };

    const setCityOptions = (citySelect, provinceId, selectedCityId = null) => {
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

    const initLocationPair = (provinceSelect, citySelect, selectedProvinceId = null, selectedCityId = null) => {
        if (!provinceSelect || !citySelect) return;

        fillProvinceOptions(provinceSelect, selectedProvinceId);
        setCityOptions(citySelect, provinceSelect.value, selectedCityId);

        provinceSelect.addEventListener('change', function () {
            setCityOptions(citySelect, this.value, null);
        });

        personInitSelect2(provinceSelect, 'انتخاب استان...');
        personInitSelect2(citySelect, 'انتخاب شهر...');
    };

    initLocationPair(
        document.getElementById('person_province_id'),
        document.getElementById('person_city_id'),
        {{ old('province_id') ? (int) old('province_id') : 'null' }},
        {{ old('city_id') ? (int) old('city_id') : 'null' }}
    );

    document.querySelectorAll('.person-edit-modal').forEach((modal) => {
        initLocationPair(
            modal.querySelector('.person-province-select'),
            modal.querySelector('.person-city-select'),
            modal.dataset.provinceId || null,
            modal.dataset.cityId || null
        );
    });
});
</script>
@endpush
@endsection
