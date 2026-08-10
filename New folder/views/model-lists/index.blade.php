@extends('layouts.app')

@section('content')
<style>
  .ml-pill{ border-radius:999px; padding:4px 10px; font-size:12px; font-weight:700; }
  .ml-code{ font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }
  .ml-code-col { width: 240px; }
  .ml-actions-col { width: 150px; }
  .code-input{
    width: 130px !important;
    min-width: 130px !important;
    text-align: center;
    letter-spacing: 1px;
  }
  .ml-row-actions{ display:flex; gap:6px; justify-content:flex-end; }
</style>

<div class="row g-3">

  {{-- فرم افزودن (کوچک‌تر) --}}
  <div class="col-lg-3">
    <div class="card shadow-sm">
      <div class="card-body">
        <h6 class="mb-3">افزودن مدل جدید</h6>

        <form method="POST" action="{{ route('model-lists.store') }}" class="row g-2">
          @csrf

          <div class="col-12">
            <label class="form-label">برند</label>
            <select name="brand" class="form-select mb-2" required>
              <option value="">انتخاب برند</option>
              @foreach(($brandOptions ?? []) as $value => $label)
                <option value="{{ $value }}" @selected(old('brand') === $value)>{{ $label }}</option>
              @endforeach
            </select>

            <div class="form-text mb-2">
              کد مدل به صورت خودکار و ترتیبی (001 تا 999) ساخته می‌شود.
            </div>

            <label class="form-label mt-2">مدل گوشی</label>
            <input name="model_name" class="form-control"
                   value="{{ old('model_name') }}"
                   placeholder="مثلاً Galaxy S24 Ultra"
                   required>
          </div>

          <div class="col-12 d-grid mt-2">
            <button class="btn btn-primary">ذخیره مدل</button>
          </div>
        </form>

        <hr>
<form method="POST" action="{{ route('model-lists.import-phone-catalog') }}" class="mb-2">
  @csrf
  <button class="btn btn-outline-success w-100">بارگذاری بانک مدل‌های موبایل</button>
  <div class="small text-muted mt-2">
    گوشی‌های معروف Samsung، Apple، Xiaomi، Huawei و... را وارد می‌کند.
  </div>
</form>
        <form method="POST" action="{{ route('model-lists.import-from-products') }}" class="mb-2">
          @csrf
          <button class="btn btn-outline-secondary w-100">دریافت مدل‌ها از کالاهای موجود</button>
          <div class="small text-muted mt-2">
            مدل‌ها از روی تنوع‌های موجود استخراج می‌شوند.
          </div>
        </form>

        <form method="POST" action="{{ route('model-lists.assign-codes') }}">
          @csrf
          <button class="btn btn-outline-primary w-100">اصلاح کدها (۳ رقمی)</button>
          <div class="small text-muted mt-2">
            کدهای ۴ رقمی/نامعتبر/تکراری را به کد ۳ رقمی یکتا تبدیل می‌کند.
          </div>
        </form>
      </div>
    </div>
  </div>

  {{-- لیست (بزرگ‌تر) --}}
  <div class="col-lg-9">
    <div class="card shadow-sm">
      <div class="card-body">

        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
          <h6 class="mb-0">مدل لیست (دسته‌بندی شده)</h6>

          <form method="GET" action="{{ route('model-lists.index') }}" class="d-flex gap-2">
            <input name="q" class="form-control form-control-sm" value="{{ $q ?? '' }}" placeholder="جستجو: مدل/کد/برند">
            <button class="btn btn-sm btn-outline-secondary">جستجو</button>
          </form>
        </div>

        <div class="accordion" id="brandAccordion">
          @php $accIndex = 0; @endphp

          @foreach(($groups ?? []) as $key => $group)
            @php
              $accIndex++;
              $headingId = "heading_{$key}";
              $collapseId = "collapse_{$key}";
              $items = $group['items'] ?? collect();
              $open = $accIndex === 1;
            @endphp

            <div class="accordion-item">
              <h2 class="accordion-header" id="{{ $headingId }}">
                <button class="accordion-button {{ $open ? '' : 'collapsed' }}" type="button"
                        data-bs-toggle="collapse" data-bs-target="#{{ $collapseId }}"
                        aria-expanded="{{ $open ? 'true' : 'false' }}" aria-controls="{{ $collapseId }}">
                  {{ $group['title'] ?? $key }}
                  <span class="ms-2 badge text-bg-light">{{ $items->count() }}</span>
                </button>
              </h2>

              <div id="{{ $collapseId }}" class="accordion-collapse collapse {{ $open ? 'show' : '' }}"
                   aria-labelledby="{{ $headingId }}" data-bs-parent="#brandAccordion">
                <div class="accordion-body">

                  @if($items->count() === 0)
                    <div class="text-muted small">موردی ثبت نشده است.</div>
                  @else
                    <div class="table-responsive">
                      <table class="table table-sm align-middle">
                        <thead>
                          <tr>
                            <th class="ml-code-col">کد ۳ رقمی</th>
                            <th>مدل</th>
                            <th style="width:160px;">برند</th>
                            <th class="text-end ml-actions-col">عملیات</th>
                          </tr>
                        </thead>
                        <tbody>
                          @foreach($items as $item)
                            <tr>
                              <td>
                                <form method="POST" action="{{ route('model-lists.update', $item) }}" class="d-flex gap-2 align-items-center">
                                  @csrf
                                  @method('PUT')

                                  <input name="code"
                                         class="form-control form-control-sm ml-code code-input"
                                         maxlength="3"
                                         inputmode="numeric"
                                         value="{{ $item->code }}"
                                         placeholder="---">

                                  <input name="model_name"
                                         class="form-control form-control-sm"
                                         value="{{ $item->model_name }}"
                                         placeholder="نام مدل"
                                         required>

                                  <button class="btn btn-sm btn-outline-primary">ذخیره</button>
                                </form>

                                @php
                                  $isInvalid = !preg_match('/^\d{3}$/', (string)($item->code ?? ''));
                                @endphp
                                @if($isInvalid)
                                  <div class="small text-danger mt-1">کد نامعتبر (برای اصلاح، «اصلاح کدها» را بزن)</div>
                                @endif
                              </td>

                              <td class="fw-semibold">{{ $item->model_name }}</td>

                              <td>
                                <span class="badge bg-info-subtle text-dark ml-pill">
                                  {{ $item->brand ?: 'سایر' }}
                                </span>
                              </td>

                              <td class="text-end">
                                <div class="ml-row-actions">
                                  <form method="POST" action="{{ route('model-lists.destroy', $item) }}"
                                        onsubmit="return confirm('حذف شود؟');">
                                    @csrf
                                    @method('DELETE')
                                    <button class="btn btn-sm btn-outline-danger">حذف</button>
                                  </form>
                                </div>
                              </td>
                            </tr>
                          @endforeach
                        </tbody>
                      </table>
                    </div>
                  @endif

                </div>
              </div>
            </div>
          @endforeach

        </div>

      </div>
    </div>
  </div>

</div>
@endsection
