@extends('layouts.app')
@section('title', 'ویرایش مشتری')
@section('content')
<form method="POST" action="{{ route('customers.update', $customer) }}">@csrf @method('PUT')
    @include('customers.form', ['customer' => $customer])
</form>
@endsection
