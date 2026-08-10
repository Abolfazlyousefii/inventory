@php
    $activeId = (string) request('category_id');
@endphp

<ul class="list-unstyled mb-0">
@foreach($nodes as $cat)
    @php
        $hasChildren = $cat->children && $cat->children->count();
        $isActive = $activeId === (string) $cat->id;
        $collapseId = 'cat-'.$cat->id;
        $open = $isActive || (string)request('open_cat') === (string)$cat->id;
    @endphp

    <li class="mb-1">
        <div class="d-flex align-items-center gap-2">
            @if($hasChildren)
                <button class="btn btn-sm btn-light border"
                        type="button"
                        data-bs-toggle="collapse"
                        data-bs-target="#{{ $collapseId }}"
                        aria-expanded="{{ $open ? 'true' : 'false' }}">
                    ▾
                </button>
            @else
                <span class="btn btn-sm btn-light border disabled">•</span>
            @endif

            <a class="text-decoration-none flex-grow-1 px-2 py-1 rounded {{ $isActive ? 'bg-primary text-white' : 'text-dark' }}"
               href="{{ route('products.index', array_filter(array_merge(request()->except('page'), ['category_id' => $cat->id]))) }}">
                {{ $cat->name }}
            </a>
        </div>

        @if($hasChildren)
            <div class="collapse ms-4 mt-1 {{ $open ? 'show' : '' }}" id="{{ $collapseId }}">
                @include('categories._tree', ['nodes' => $cat->children])
            </div>
        @endif
    </li>
@endforeach
</ul>
    