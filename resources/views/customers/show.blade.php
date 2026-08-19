@extends('layouts.app')
@section('title', $customer->display_name)
@section('content')
<h1>{{ $customer->display_name }}</h1>
@foreach($customer->phones as $phone)
<div>{{ $phone->phone }} @if($phone->is_primary)<span>اصلی</span>@endif</div>
@endforeach
@endsection
