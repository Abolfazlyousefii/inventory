<x-app-layout>
	<x-slot name="title">پروفایل کاربری</x-slot>

	<div dir="rtl" class="py-4">
		<div class="container-fluid">

			<div class="card border-0 shadow-sm rounded-4 overflow-hidden mb-4">
				<div class="p-4 text-white" style="background:#16354f">
					<div class="d-flex align-items-center gap-4">
						<div class="rounded-circle bg-white bg-opacity-25 d-flex align-items-center justify-content-center"
						     style="width:90px;height:90px;font-size:38px;font-weight:800">
							{{ mb_substr($user->name,0,1) }}
						</div>

						<div>
							<h1 class="h3 fw-bold mb-2">{{ $user->name }}</h1>
							<div class="opacity-75">{{ $user->email }}</div>
							<span class="badge bg-light text-dark mt-3 px-3 py-2">
                                حساب فعال
                            </span>
						</div>
					</div>
				</div>
			</div>

			<div class="row g-4">

				<div class="col-lg-7">
					<div class="card border-0 shadow-sm rounded-4 p-4">
						@include('profile.partials.update-profile-information-form')
					</div>
				</div>

				<div class="col-lg-5">
					<div class="card border-0 shadow-sm rounded-4 p-4 text-white" style="background:#16354f">
						@include('profile.partials.update-password-form')
					</div>
				</div>

				<div class="col-12">
					<div class="card border-danger border-opacity-25 shadow-sm rounded-4 p-4">
						@include('profile.partials.delete-user-form')
					</div>
				</div>

			</div>

		</div>
	</div>
</x-app-layout>
