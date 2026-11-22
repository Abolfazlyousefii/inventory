{{-- Shared cancellation confirmation for archive and detail pages. The existing destroy route owns all writes. --}}
<dialog class="pr-cancel-dialog" data-pr-cancel-dialog aria-labelledby="pr-cancel-title">
    <form method="post" data-pr-cancel-form>
        @csrf
        @method('DELETE')
        <input type="hidden" name="version" data-pr-cancel-version>
        <h2 id="pr-cancel-title">تأیید ابطال سند برگشت از خرید</h2>
        <dl class="pr-cancel-details">
            <div><dt>شماره سند</dt><dd data-pr-cancel-number></dd></div>
            <div><dt>تأمین‌کننده</dt><dd data-pr-cancel-supplier></dd></div>
            <div><dt>خرید مرجع</dt><dd data-pr-cancel-purchase></dd></div>
            <div><dt>انبار مبدأ</dt><dd data-pr-cancel-warehouse></dd></div>
            <div><dt>تعداد اقلام سند</dt><dd data-pr-cancel-items-count></dd></div>
            <div><dt>تعداد کل کالا</dt><dd data-pr-cancel-quantity></dd></div>
        </dl>
        <p class="pr-cancel-warning" role="alert">با ابطال این سند، تعداد کالاهای برگشتی به موجودی آزاد انبار <strong data-pr-cancel-warehouse-warning></strong> برمی‌گردد و اثر مالی برگشت در حساب تأمین‌کننده معکوس می‌شود. اگر کالا هنوز نزد تأمین‌کننده است، این عملیات ممکن است با موجودی فیزیکی انبار مغایرت ایجاد کند. پیش از تأیید، وضعیت واقعی کالا را بررسی کنید.</p>
        <label for="pr-cancel-reason">دلیل ابطال <span class="pr-required">(اجباری)</span></label>
        <textarea class="form-control" id="pr-cancel-reason" name="reason" rows="3" maxlength="1000" required placeholder="علت ابطال را به‌روشنی بنویسید" aria-describedby="pr-cancel-help"></textarea>
        <small id="pr-cancel-help">دلیل ابطال، کاربر و زمان در تاریخچه سند ثبت می‌شود.</small>
        <div class="pr-cancel-actions">
            <button class="btn btn-outline-secondary" type="button" data-pr-cancel-close>انصراف</button>
            <button class="btn btn-danger" type="submit" data-pr-cancel-submit>تأیید ابطال سند</button>
        </div>
    </form>
</dialog>
