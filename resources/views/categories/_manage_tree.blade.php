@php
  $level = $level ?? 0;
@endphp

<style>

	.cat-item{
		border-radius:18px;
		padding:14px 16px;
		transition:.2s ease;
		position:relative;
	}


	.cat-item.main-category{
		background:
				linear-gradient(135deg,#ffffff,#f8fafc);
		border:1px solid rgba(13,110,253,.25);
		box-shadow:
				0 8px 25px rgba(13,110,253,.08);
	}


	.cat-item.main-category:before{
		content:"";
		position:absolute;
		right:0;
		top:12px;
		bottom:12px;
		width:5px;
		border-radius:10px;
		background:#0d6efd;
	}


	.cat-item.sub-category{
		background:#fafafa;
		border:1px solid #e8edf3;
		margin-top:8px;
	}


	.cat-item.sub-category:before{
		content:"";
		position:absolute;
		right:0;
		top:14px;
		bottom:14px;
		width:3px;
		border-radius:10px;
		background:#adb5bd;
	}


	.cat-title{
		font-size:16px;
		font-weight:800;
	}


	.sub-category .cat-title{
		font-size:14px;
		font-weight:600;
	}


	.cat-badge{
		border-radius:999px;
		padding:5px 12px;
		font-size:12px;
		font-weight:700;
	}


	.main-badge{
		background:#e7f1ff;
		color:#0d6efd;
	}


	.sub-badge{
		background:#eeeeee;
		color:#555;
	}


	.cat-code{
		background:#111827;
		color:white;
		border-radius:10px;
		padding:5px 10px;
		font-family:ui-monospace;
	}


	.cat-actions .btn{
		border-radius:12px;
	}


</style>

<ul class="list-unstyled mb-0">
  @foreach($nodes as $cat)
    @php
      $hasChildren = $cat->children && $cat->children->count();
      $indent = $level * 18;
    @endphp

    <li class="mb-2" style="margin-right: {{ $indent }}px;">
	    <div class="cat-item
{{ $cat->parent_id ? 'sub-category' : 'main-category' }}
d-flex align-items-center justify-content-between gap-2">
		    <div class="d-flex align-items-center gap-2 flex-wrap">
          <span class="badge bg-light text-dark cat-code">کد: {{ $cat->code ?? '--' }}</span>
			    <span class="cat-title">{{ $cat->name }}</span>

			    @if($cat->parent_id)

				    <span class="cat-badge sub-badge">
    زیر‌دسته
</span>

			    @else

				    <span class="cat-badge main-badge">
    ★ دسته اصلی
</span>

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
