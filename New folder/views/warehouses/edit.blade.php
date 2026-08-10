@extends('layouts.app')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="page-title mb-0">ویرایش انبار</h4>
    <a class="btn btn-outline-secondary" href="{{ route('warehouses.index') }}">بازگشت</a>
</div>

<div class="card">
    <div class="card-body">
        <form method="POST" action="{{ route('warehouses.update', $warehouse) }}" class="row g-3">
            @csrf
            @method('PUT')

            <div class="col-md-6">
                <label class="form-label">نام انبار</label>
                <input name="name" class="form-control" required value="{{ old('name', $warehouse->name) }}">
            </div>

            <div class="col-12">
                <button class="btn btn-primary">ذخیره</button>
            </div>
        </form>
    </div>
</div>
@endsection
