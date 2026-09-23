{{--
    A panel header with a count, and — depending on what it was given — a way to narrow the rows under it.

    THREE MODES, AND THE ORDER MATTERS BECAUSE THIS PARTIAL IS INCLUDED BY TWENTY VIEWS. With a `query` it
    renders a GET search form and the grand total: the narrowing happens on the SERVER and the number is a
    fact about the application. With a `filter` it renders the in-page JavaScript row filter and a live
    "shown of total": that is still right for a panel whose rows are all in the response by definition —
    the resource index, the health indicators, the settings console. With neither it renders the count
    alone, which is what overview's four panels, graph's three and five other views ask for.

    THE SEARCH FORM RE-SUBMITS THE STATE ITS OWN INPUT DOES NOT CARRY. `hiddenFields()` emits the sort, the
    direction, the size and anything the page asked the listing to carry; without them, searching from a
    sorted page hands back the matching rows in the ORIGINAL order, which reads as "sorting is broken"
    rather than as a form that lost a parameter. `q` is not among them on purpose — the input below owns
    that name, and a hidden field sharing it would submit ahead of whatever was typed. Asserted by
    AdminTableListingTest's "carries the ordering through the search form and the rows-per-page form".

    `{{ number_format($count) }} total` IS THE GRAND TOTAL, not the row count of the response: on page 2 of
    a narrowed listing it still reads the size of the whole result set. AdminTableListingTest pins both
    readings of it — `7 total` unnarrowed and `5 total` under `?q=orders` — and AdminTablePagerTest pins the
    one reading a single-page fixture cannot: five matches behind a page of two.

    The wording is not free, though: data-list.blade.php still hand-rolls the same words in its own
    <header>, and that is what tests/Browser/AdminDataBrowserTest.php's `assertSee('3 total')` hits today —
    nothing in that file touches this branch. Keeping the literal identical is what lets the data browser
    move onto this partial without rewriting a browser assertion.
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
