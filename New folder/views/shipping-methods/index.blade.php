@extends('layouts.app')

@section('content')
<div class="row g-3">
  <div class="col-lg-4">
    <div class="card shadow-sm">
      <div class="card-body">
        <h6 class="mb-3">افزودن روش ارسال</h6>

        <form method="POST" action="{{ route('shipping-methods.store') }}" class="row g-2">
          @csrf
          <div class="col-12">
            <label class="form-label">نام روش ارسال</label>
            <input name="name" class="form-control" value="{{ old('name') }}" required>
          </div>

          <div class="col-12">
            <label class="form-label">هزینه ارسال (ریال)</label>
            <input type="number" min="0" name="price" class="form-control" value="{{ old('price', 0) }}" required>
          </div>

          <div class="col-12 d-grid mt-2">
            <button class="btn btn-primary">ذخیره</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-8">
    <div class="card shadow-sm">
      <div class="card-body">
        <h6 class="mb-3">لیست روش‌های ارسال</h6>

        <div class="table-responsive">
          <table class="table table-sm align-middle">
            <thead>
              <tr>
                <th>نام</th>
                <th>هزینه</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              @forelse($shippingMethods as $method)
                <tr>
                  <td>
                    <form method="POST" action="{{ route('shipping-methods.update', $method) }}" class="d-flex gap-2 align-items-center">
                      @csrf
                      @method('PUT')
                      <input name="name" class="form-control" value="{{ $method->name }}" required>
                      <input type="number" min="0" name="price" class="form-control" value="{{ \App\Support\Currency::toRial($method->price) }}" style="max-width:170px" required>
                      <button class="btn btn-outline-primary btn-sm">ذخیره</button>
                    </form>
                  </td>
                  <td style="width:140px">{{ \App\Support\Currency::formatRial($method->price) }}</td>
                  <td class="text-end" style="width:90px">
                    <form method="POST" action="{{ route('shipping-methods.destroy', $method) }}" onsubmit="return confirm('حذف شود؟')">
                      @csrf
                      @method('DELETE')
                      <button class="btn btn-outline-danger btn-sm">حذف</button>
                    </form>
                  </td>
                </tr>
              @empty
                <tr>
                  <td colspan="3" class="text-center text-muted py-4">هنوز روشی ثبت نشده است.</td>
                </tr>
              @endforelse
            </tbody>
          </table>
        </div>

        {{ $shippingMethods->links() }}
      </div>
    </div>
  </div>
</div>
@endsection
