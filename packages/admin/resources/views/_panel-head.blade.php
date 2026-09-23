{{--
    A panel header with a count, and — depending on what it was given — a way to narrow the rows under it.

    THREE MODES, AND THE ORDER MATTERS BECAUSE THIS PARTIAL IS INCLUDED BY TWENTY VIEWS. With a `query` it
    renders a GET search form and the grand total: the narrowing happens on the SERVER and the number is a
    fact about the application. With a `filter` it renders the in-page JavaScript row filter and a live
    "shown of total": that is still right for a panel whose rows are all in the response by definition —
    the resource index, the health indicators, the settings console. With neither it renders the count
    alone, which is what overview's four panels, graph's three and five other views ask for.

    `{{ number_format($count) }} total` IS A PINNED STRING. tests/Browser/AdminDataBrowserTest.php asserts
    `assertSee('3 total')` and `assertSee('1 total')` against the data browser's listing, which hand-rolled
    exactly this label before it moved here.
--}}
<header>
    <h2>{{ $title }}</h2>
    <span class="spacer"></span>
    @isset($query)
        <form method="get" action="{{ $query->path }}" class="inline" role="search">
            @foreach ($query->hiddenFields() as $field)
                <input type="hidden" name="{{ $field['name'] }}" value="{{ $field['value'] }}">
            @endforeach
            <input class="filter" type="search" name="{{ $query->qualifier === '' ? 'q' : $query->qualifier.'_q' }}"
                   value="{{ $query->search }}" placeholder="{{ $placeholder ?? 'Search…' }}"
                   aria-label="{{ $placeholder ?? 'Search rows' }}">
            <button class="act" type="submit">Search</button>
            @if ($query->isFiltered())<a class="act" href="{{ $query->link(['q' => null, 'page' => null]) }}">Clear</a>@endif
        </form>
        <span class="meta">{{ number_format($count) }} total</span>
    @elseif (isset($filter))
        <input class="filter" type="search" data-filter="{{ $filter }}"
               placeholder="{{ $placeholder ?? 'Filter…' }}" aria-label="{{ $placeholder ?? 'Filter rows' }}">
        <span class="meta" data-filter-count>{{ $count }}</span>
    @else
        <span class="meta">{{ $count }}</span>
    @endisset
</header>
