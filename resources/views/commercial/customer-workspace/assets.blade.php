@push('styles')<link rel="stylesheet" href="{{ asset('css/account-statement-document.css') }}?v=20261114-shared-book"><link rel="stylesheet" href="{{ asset('css/customer-experience.css') }}?v={{ filemtime(public_path('css/customer-experience.css')) }}">@endpush
@push('scripts')<script defer src="{{ asset('js/customer-experience.js') }}?v={{ filemtime(public_path('js/customer-experience.js')) }}"></script>@endpush
@push('styles')<link rel="stylesheet" href="{{ asset('css/customer-followups.css') }}?v={{ filemtime(public_path('css/customer-followups.css')) }}">@endpush
@push('scripts')<script defer src="{{ asset('js/customer-followups.js') }}?v={{ filemtime(public_path('js/customer-followups.js')) }}"></script>@endpush
