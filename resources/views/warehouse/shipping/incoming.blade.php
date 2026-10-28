<style>{!! file_get_contents(public_path('css/shipping-incoming.css')) !!}</style>
<div class="modal fade" id="shippingIncomingModal" tabindex="-1" aria-labelledby="shippingIncomingTitle" aria-hidden="true" data-url="{{ route('warehouse.shipping.incoming') }}">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title fs-6" id="shippingIncomingTitle">فاکتورهای در راه ارسال</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="بستن پنجره"></button>
            </div>
            <div class="modal-body">
                <p class="incoming-intro" id="shippingIncomingIntro">فاکتورهای صف جمع‌آوری؛ هنوز آمادهٔ ارسال نیستند.</p>
                <div id="shippingIncomingState" role="status" aria-live="polite"></div>
                <div id="shippingIncomingList"></div>
                <button class="btn btn-outline-secondary btn-sm incoming-more" type="button" id="shippingIncomingMore" hidden>نمایش بیشتر</button>
                <button class="btn btn-outline-primary btn-sm" type="button" id="shippingIncomingRetry" hidden>تلاش مجدد</button>
            </div>
            <div class="modal-footer">
                <span class="incoming-footer-note">فقط مشاهده · آدرس ثبت‌شده روی فاکتور</span>
                <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">بستن</button>
            </div>
        </div>
    </div>
</div>
<script>{!! file_get_contents(public_path('js/shipping-incoming.js')) !!}</script>
