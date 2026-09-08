@extends('layouts.app')

@section('content')

	<div class="roles-page" dir="rtl">

		<style>

			.roles-page{
				padding:30px;
			}


			/* Hero */

			.roles-hero{

				background:
						linear-gradient(
								135deg,
								#ffffff,
								#f4f7ff
						);

				border-radius:32px;

				padding:35px;

				display:flex;

				justify-content:space-between;

				align-items:center;

				margin-bottom:35px;

				border:1px solid #edf0f7;

			}


			.roles-title{

				font-size:32px;

				font-weight:900;

				margin:0;

			}


			.roles-desc{

				margin-top:10px;

				color:#6c757d;

			}



			/* Button */


			.new-role{

				padding:14px 28px;

				border-radius:18px;

				font-weight:800;

			}





			/* Cards */


			.roles-grid{

				display:grid;

				grid-template-columns:
    repeat(auto-fill,minmax(280px,1fr));

				gap:25px;

			}





			.role-card{

				background:white;

				border-radius:28px;

				padding:25px;

				border:1px solid #edf0f5;

				position:relative;

				overflow:hidden;

				transition:.25s;


			}



			.role-card:hover{

				transform:translateY(-8px);

				box-shadow:
						0 25px 60px rgba(0,0,0,.10);

			}




			.role-card::before{

				content:"";

				position:absolute;

				top:0;

				right:0;

				width:100%;

				height:5px;

				background:
						linear-gradient(
								90deg,
								#0d6efd,
								#6f42c1
						);

			}





			.role-top{

				display:flex;

				align-items:center;

				gap:15px;

			}




			.role-avatar{

				width:55px;

				height:55px;

				border-radius:20px;

				background:#eef5ff;

				color:#0d6efd;

				display:flex;

				align-items:center;

				justify-content:center;

				font-size:24px;

				font-weight:900;

			}



			.role-name{

				font-size:20px;

				font-weight:900;

			}





			.permission-pill{

				margin-top:25px;

				display:inline-flex;

				padding:10px 18px;

				border-radius:999px;

				background:#f1f3f5;

				font-weight:700;

			}





			.role-actions{

				display:flex;

				gap:10px;

				margin-top:25px;

			}



			.role-actions .btn{

				flex:1;

				border-radius:15px;

				font-weight:700;

			}



			@media(max-width:700px){

				.roles-page{

					padding:15px;

				}


				.roles-hero{

					flex-direction:column;

					gap:20px;

					align-items:stretch;

				}


				.new-role{

					width:100%;

				}

			}


		</style>



		<div class="roles-hero">


			<div>

				<h1 class="roles-title">

					🛡 مدیریت نقش‌ها

				</h1>


				<div class="roles-desc">

					کنترل سطح دسترسی کاربران سیستم

				</div>


			</div>



			<a href="{{ route('admin.roles.create') }}"
			   class="btn btn-primary new-role">

				+ ایجاد نقش

			</a>


		</div>





		<div class="roles-grid">



			@foreach($roles as $role)


				<div class="role-card">


					<div class="role-top">


						<div class="role-avatar">

							{{ mb_substr($role->name,0,1) }}

						</div>


						<div class="role-name">

							{{ $role->name }}

						</div>


					</div>




					<div class="permission-pill">

						🛡

						{{ $role->permissions_count ?? $role->permissions->count() }}

						دسترسی

					</div>





					<div class="role-actions">


						<a href="{{ route('admin.roles.edit',$role) }}"
						   class="btn btn-outline-primary">


							ویرایش

						</a>




						@unless(in_array($role->name,$protectedRoleNames,true))


							<form action="{{ route('admin.roles.destroy',$role) }}"
							      method="POST"
							      class="flex-fill"
							      onsubmit="return confirm('آیا از حذف این نقش مطمئن هستید؟')">


								@csrf

								@method('DELETE')


								<button class="btn btn-outline-danger w-100">

									حذف

								</button>


							</form>


						@endunless



					</div>



				</div>



			@endforeach



		</div>



	</div>


@endsection