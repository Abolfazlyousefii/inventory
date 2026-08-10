@php
  $level = $level ?? 0;
@endphp

<style>
  .cat-item{
    border: 1px solid #e8edf3;
    border-radius: 14px;
    background: #fff;
    padding: 10px 12px;
  }
  .cat-badge{
    border-radius: 999px;
    padding: 3px 10px;
    font-size: 12px;
    font-weight: 700;
    white-space: nowrap;
  }
  .cat-actions .btn{
    border-radius: 12px;
  }
  .cat-code{
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
    letter-spacing: 1px;
  }
</style>

<ul class="list-unstyled mb-0">
  @foreach($nodes as $cat)
    @php
      $hasChildren = $cat->children && $cat->children->count();
      $indent = $level * 18;
    @endphp

    <li class="mb-2" style="margin-right: {{ $indent }}px;">
      <div class="cat-item d-flex align-items-center justify-content-between gap-2">
        <div class="d-flex align-items-center gap-2 flex-wrap">
          <span class="badge bg-light text-dark cat-code">کد: {{ $cat->code ?? '--' }}</span>
          <span class="fw-semibold">{{ $cat->name }}</span>

          @if($cat->parent_id)
            <span class="badge bg-secondary-subtle text-dark cat-badge">زیر‌دسته</span>
          @else
            <span class="badge bg-primary-subtle text-dark cat-badge">دسته اصلی</span>
          @endif

          @if($hasChildren)
            <span class="badge bg-light text-dark cat-badge">{{ $cat->children->count() }} زیر‌دسته</span>
          @endif
        </div>

        <div class="d-flex gap-1 cat-actions">
          @canPermission('categories.edit')
          <a class="btn btn-sm btn-outline-secondary" href="{{ route('categories.edit', $cat) }}">ویرایش</a>
          @endcanPermission
          @canPermission('categories.delete')
          <form method="POST" action="{{ route('categories.destroy', $cat) }}" onsubmit="return confirm('حذف شود؟')">
            @csrf
            @method('DELETE')
            <button class="btn btn-sm btn-outline-danger">حذف</button>
          </form>
          @endcanPermission
        </div>
      </div>

      @if($hasChildren)
        @include('categories._manage_tree', ['nodes' => $cat->children, 'level' => $level + 1])
      @endif
    </li>
  @endforeach
</ul>
