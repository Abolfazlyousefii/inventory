<div class="modal fade" id="voidSalesReturnModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="POST" id="voidSalesReturnForm">
            @csrf
            <div class="modal-header"><h6 class="modal-title">حذف سند برگشت از فروش</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body small">
                <div class="mb-2">سند: <strong data-void-doc>—</strong></div>
                <div class="mb-2">مشتری: <strong data-void-customer>—</strong></div>
                <div class="mb-2">مبلغ: <strong data-void-amount>—</strong> ریال</div>
                <div class="mb-3">تعداد کالا: <strong data-void-qty>—</strong></div>
                <label class="form-label">دلیل حذف <span class="text-danger">*</span></label>
                <textarea class="form-control" name="reason" rows="3" required></textarea>
                <div class="text-muted mt-2">این عملیات سند را Hard Delete نمی‌کند؛ سند ابطال شده و آثار موجودی و حساب مشتری برگشت می‌خورد.</div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">انصراف</button><button class="btn btn-danger">تأیید حذف</button></div>
        </form>
    </div>
</div>
<script>
document.getElementById('voidSalesReturnModal')?.addEventListener('show.bs.modal',e=>{const b=e.relatedTarget,m=e.currentTarget;m.querySelector('#voidSalesReturnForm').action=b?.dataset.voidUrl||'#';m.querySelector('[data-void-doc]').textContent=b?.dataset.doc||'—';m.querySelector('[data-void-customer]').textContent=b?.dataset.customer||'—';m.querySelector('[data-void-amount]').textContent=b?.dataset.amount||'—';m.querySelector('[data-void-qty]').textContent=b?.dataset.qty||'—';});
</script>
