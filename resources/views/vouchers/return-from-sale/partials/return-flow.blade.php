@unless($isAppliedEdit ?? false)
<div class="col-12">
    <div class="sr-card sr-compact">
        <div class="row g-3">
            <div class="col-md-5">
                <label class="form-label" for="returnFlow">نوع عملیات برگشت</label>
                <select class="form-select form-select-sm" name="return_flow" id="returnFlow">
                    <option value="physical" @selected(old('return_flow', $document?->return_flow ?? 'physical') === 'physical')>تحویل فیزیکی کالا به انبار</option>
                    <option value="financial_only" @selected(old('return_flow', $document?->return_flow) === 'financial_only')>صرفاً مالی؛ بدون تحویل کالا</option>
                </select>
                <div class="small sr-muted mt-1">تحویل فیزیکی به انبار مرکزی یا مرجوعی، فقط پس از تأیید تعداد توسط مدیر انبار، موجودی ایجاد می‌کند.</div>
            </div>
            <div class="col-md-7">
                <label class="form-label" for="financialOnlyReason">دلیل عدم تحویل کالا <span class="small sr-muted">(برای برگشت صرفاً مالی الزامی)</span></label>
                <textarea class="form-control form-control-sm" name="financial_only_reason" id="financialOnlyReason" rows="2" maxlength="1000">{{ old('financial_only_reason', $document?->financial_only_reason) }}</textarea>
                @error('financial_only_reason')<div class="text-danger small">{{ $message }}</div>@enderror
            </div>
        </div>
    </div>
</div>
@endunless
