@extends('layouts.app')
@section('title','پیگیری‌های من')
@include('commercial.customer-workspace.assets')
@section('content')
<div class="ce-workspace" data-cf-workspace><div class="page-head"><div><div class="eyebrow">بازرگانی و فروش</div><h1>پیگیری‌های من</h1><p class="ce-muted">کارهای امروز و قرارهای بعدی با مشتریان شما</p></div><a class="tool-button" href="{{ route('commercial.customers.index') }}">مشتریان من ←</a></div>
@include('commercial.customer-workspace.followup-list')</div>
@endsection
