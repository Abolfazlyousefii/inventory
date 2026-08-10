@extends('layouts.app')

@section('content')
<style>
  .cat-head{
    background: linear-gradient(180deg, rgba(13,110,253,.10), rgba(13,110,253,0));
    border: 1px solid #e8edf3;
    border-radius: 16px;
    padding: 14px 16px;
  }
  .cat-pill{
    border-radius: 999px;
    padding: 3px 10px;
    font-size: 12px;
    font-weight: 700;
  }
  .cat-code{
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
    letter-spacing: 1px;
  }
</style>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <div>
    <h4 class="mb-0">دسته‌بندی‌ها</h4>
    <div class="text-muted small mt-1">نمایش ساده والد/زیر‌دسته (بدون تب‌بندی)</div>
  </div>

  <div class="d-flex gap-2 flex-wrap">
    @canPermission('categories.create')
    <a class="btn btn-primary" href="{{ route('categories.create') }}">+ افزودن دسته‌بندی</a>
    @endcanPermission

    <form method="POST" action="{{ route('categories.fixCodes') }}" onsubmit="return confirm('کد همه دسته‌ها ۲ رقمی یونیک شود؟');">
      @csrf
      @canPermission('categories.edit')
      <button class="btn btn-outline-secondary">اصلاح کدها (۲ رقمی)</button>
      @endcanPermission
    </form>
  </div>
</div>

<div class="cat-head mb-3">
  <div class="small text-muted">
    کد دسته‌بندی‌ها به صورت <span class="cat-code">00 تا 99</span> و به شکل رندوم/یونیک ساخته می‌شود.
  </div>
</div>

<div class="card shadow-sm">
  <div class="card-body">
    @if($rootCategories->isEmpty())
      <div class="text-center text-muted py-4">هیچ دسته‌بندی ثبت نشده.</div>
    @else
      <div class="small text-muted mb-3">نمایش ساده دسته‌بندی‌ها و زیر‌دسته‌ها</div>
      @include('categories._manage_tree', ['nodes' => $rootCategories, 'level' => 0])
    @endif
  </div>
</div>
@endsection
