@extends('layouts.app')

@section('content')
<div class="container py-4">
    <h1 class="mb-3">مدیریت API تغییرات موجودی و قیمت</h1>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    @if(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    @if(!($dbReady ?? false))
        <div class="alert alert-warning">
            جداول این ماژول هنوز ساخته نشده‌اند. لطفاً دستور <code>php artisan migrate</code> را اجرا کنید.
        </div>
    @endif

    <div class="card mb-4">
        <div class="card-body">
            <form method="POST" action="{{ route('inventory-webhooks.update') }}">
                @csrf
                @method('PUT')

                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" name="is_enabled" value="1" id="is_enabled" {{ old('is_enabled', $setting?->is_enabled) ? 'checked' : '' }}>
                    <label class="form-check-label" for="is_enabled">ارسال خودکار API فعال باشد</label>
                </div>

                <div class="mb-3">
                    <label class="form-label">Webhook URL</label>
                    <input type="url" name="endpoint_url" class="form-control" value="{{ old('endpoint_url', $setting?->endpoint_url) }}" placeholder="https://example.com/webhook/inventory">
                </div>

                <div class="mb-3">
                    <label class="form-label">Secret (اختیاری)</label>
                    <input type="text" name="secret" class="form-control" value="{{ old('secret', $setting?->secret) }}">
                </div>

                <div class="mb-3">
                    <label class="form-label">Timeout (ثانیه)</label>
                    <input type="number" name="timeout_seconds" min="1" max="30" class="form-control" value="{{ old('timeout_seconds', $setting?->timeout_seconds ?? 5) }}">
                </div>

                <button class="btn btn-primary" {{ !($dbReady ?? false) ? 'disabled' : '' }}>ذخیره تنظیمات</button>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header">گزارش ارسال‌ها (آخرین 100 رکورد)</div>
        <div class="card-body table-responsive">
            <table class="table table-striped align-middle">
                <thead>
                    <tr>
                        <th>#</th><th>رویداد</th><th>محصول/تنوع ارسال‌شده</th><th>وضعیت</th><th>دفعات تلاش</th><th>تلاش بعدی</th><th>کد پاسخ</th><th>زمان ارسال</th><th>خطا</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($logs as $log)
                    <tr>
                        <td>{{ $log->id }}</td>
                        <td>{{ $log->event }}</td>
                        <td style="max-width:380px;white-space:normal;">
                            @php($payload = (array) ($log->payload ?? []))
                            @php($variantRows = [])
                            @if(!empty($payload['variants']) && is_array($payload['variants']))
                                @php($variantRows = $payload['variants'])
                            @elseif(!empty($payload['payload']['variants']) && is_array($payload['payload']['variants']))
                                @php($variantRows = $payload['payload']['variants'])
                            @endif

                            @if(!empty($payload['payload']['product_id']) || !empty($payload['payload']['sku']) || !empty($payload['payload']['name']))
                                <div>کالا: {{ $payload['payload']['name'] ?? '-' }} (ID: {{ $payload['payload']['product_id'] ?? '-' }})</div>
                                <div class="small text-muted">SKU: {{ $payload['payload']['sku'] ?? '-' }}</div>
                            @endif

                            @if(!empty($payload['payload']['movement_id']))
                                <div class="small">حرکت انبار: #{{ $payload['payload']['movement_id'] }} | محصول: {{ $payload['payload']['product_id'] ?? '-' }}</div>
                            @endif

                            @if(!empty($variantRows))
                                <div class="small mt-1">تنوع‌ها:
                                    @foreach(array_slice($variantRows, 0, 5) as $v)
                                        <div class="border rounded p-1 mb-1 bg-light-subtle">
                                            <div>ID: {{ $v['id'] ?? '-' }} | قیمت: {{ $v['price'] ?? '-' }} | موجودی: {{ $v['balance'] ?? '-' }}</div>
                                            <div>محصول: {{ $v['product_name'] ?? '-' }} | کد محصول: {{ $v['product_code'] ?? '-' }}</div>
                                            <div>تنوع: {{ $v['variant_name'] ?? '-' }} | کد تنوع: {{ $v['variant_code'] ?? '-' }}</div>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </td>
                        <td>{{ $log->status }}</td>
                        <td>{{ $log->attempts ?? 0 }}</td>
                        <td>{{ $log->next_retry_at?->format('Y-m-d H:i:s') ?? '-' }}</td>
                        <td>{{ $log->response_code ?? '-' }}</td>
                        <td>{{ $log->sent_at?->format('Y-m-d H:i:s') ?? '-' }}</td>
                        <td style="max-width:350px;white-space:normal;">{{ $log->error_message ?? '-' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="text-center text-muted">هنوز ارسالی ثبت نشده است.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
