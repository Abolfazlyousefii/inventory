@extends('layouts.app')
@section('title', 'افزودن مشتری')
@section('content')
<form method="POST" action="{{ route('customers.store') }}">@csrf
    @include('customers.form', ['customer' => null])
</form>
@endsection
