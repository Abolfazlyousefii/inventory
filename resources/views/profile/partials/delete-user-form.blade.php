<section dir="rtl">

	<h3 class="fw-bold text-danger">حذف حساب کاربری</h3>
	<p class="text-muted">این عملیات غیرقابل بازگشت است.</p>

	<button type="button"
	        class="btn btn-outline-danger mt-3"
	        data-bs-toggle="modal"
	        data-bs-target="#deleteAccountModal">
		حذف حساب
	</button>

	<div class="modal fade" id="deleteAccountModal">
		<div class="modal-dialog modal-dialog-centered">
			<div class="modal-content">

				<form method="post" action="{{ route('profile.destroy') }}">
					@csrf
					@method('delete')

					<div class="modal-header">
						<h5 class="modal-title">تایید حذف حساب</h5>
					</div>

					<div class="modal-body">
						<p>برای حذف حساب رمز عبور خود را وارد کنید.</p>
						<input class="form-control" name="password" type="password">
						<x-input-error :messages="$errors->userDeletion->get('password')"/>
					</div>

					<div class="modal-footer">
						<button class="btn btn-secondary" data-bs-dismiss="modal">انصراف</button>
						<button class="btn btn-danger">حذف دائمی</button>
					</div>

				</form>

			</div>
		</div>
	</div>

</section>
