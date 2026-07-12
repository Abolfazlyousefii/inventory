@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if($errors->any())
<div class="alert alert-danger"><strong>لطفاً خطاهای فرم را اصلاح کنید.</strong><ul class="mb-0 mt-2">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
@endif
