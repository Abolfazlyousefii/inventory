@extends('layouts.app')

@section('content')
<div class="container">
<h3>بازیابی فروش Local</h3>

<form method="POST" action="/recovery/import" enctype="multipart/form-data">
@csrf
<input type="file" name="file" class="form-control mb-3">
<button class="btn btn-primary">شروع انتقال</button>
</form>
</div>
@endsection
