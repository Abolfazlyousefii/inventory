<div class="finance-actions">
  @if($isExpired)
    <span class="small text-danger align-self-center" data-expired-message>رزرو منقضی شده</span>
  @else
    <a class="btn btn-sm btn-success" data-finance-approve href="{{ route('preinvoice.draft.finance', $o->uuid) }}">تایید</a>
    <span class="small text-danger align-self-center d-none" data-expired-message>رزرو منقضی شده</span>
  @endif
  <form method="POST" action="{{ route('preinvoice.draft.return', $o->uuid) }}" class="d-inline" onsubmit="return confirm('پیش‌فاکتور به فروشنده ارجاع شود؟')">
    @csrf
    <input type="hidden" name="reason" value="ارجاع توسط مالی از صف مالی">
    <button class="btn btn-sm btn-outline-warning">ارجاع</button>
  </form>
  <form method="POST" action="{{ route('preinvoice.draft.cancel', $o->uuid) }}" class="d-inline" onsubmit="return confirm('پیش‌فاکتور کنسل شود؟')">
    @csrf
    <input type="hidden" name="reason" value="کنسل توسط مالی از صف مالی">
    <button class="btn btn-sm btn-outline-danger">کنسل</button>
  </form>
  <a class="btn btn-sm btn-outline-dark" href="{{ route('preinvoice.print', $o->uuid) }}" target="_blank">پرینت</a>
</div>
