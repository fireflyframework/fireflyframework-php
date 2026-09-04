{{-- A panel header with an optional filter box and a live "shown of total" readout. --}}
<header>
    <h2>{{ $title }}</h2>
    <span class="spacer"></span>
    @isset($filter)
        <input class="filter" type="search" data-filter="{{ $filter }}"
               placeholder="{{ $placeholder ?? 'Filter…' }}" aria-label="{{ $placeholder ?? 'Filter rows' }}">
        <span class="meta" data-filter-count>{{ $count }}</span>
    @else
        <span class="meta">{{ $count }}</span>
    @endisset
</header>
