{{--
    The range readout, the rows-per-page control and the page window.

    Extracted from data-list.blade.php, where it was bound to a DataListing, `$resource?->slug` and
    `$settings->url('data')` — three things no other page has — and where every one of its links was built
    by concatenating four "do not forget to carry this" strings. It takes a ListingPage and the
    ListingQuery that produced it, and every link comes out of `link()`.

    `$slice`, NOT `$page`: AdminAction::render() already puts an AdminPage in `$page` on every view, so a
    partial variable of that name would shadow the navigation's own model.

    THE ROWS CONTROL SUBMITS WITHOUT JAVASCRIPT. It was a <select onchange="this.form.submit()"> with no
    button, which is a control that does nothing at all for a keyboard, a text browser or a page whose
    script failed — on a dashboard whose entire value proposition is working in a network-isolated
    environment with no build step. The onchange stays as a convenience; the button is what makes it a
    control.

    THE PAGED BRANCH AT THE BOTTOM IS THE STATE-CARRYING ONE, and it renders only when there is more than
    one page — so a seven-row fixture cannot see it at all, and a page link that silently dropped `q` or
    `sort` would widen the listing back to every row with nothing failing. Every link it draws goes through
    ListingPage::link(), and packages/admin/tests/AdminTablePagerTest.php asserts that over a fixture built
    to page (AdminTablePagedCapstoneTestCase: twenty-one rows, a page size of 2, eleven pages).
--}}
@php
    /** @var \Firefly\Admin\Table\ListingPage $slice */
    /** @var \Firefly\Admin\Table\ListingQuery $query */
    $window = $slice->window();
@endphp
<div class="pager">
    <span class="range">
        {{ number_format($slice->from()) }}–{{ number_format($slice->to()) }} of {{ number_format($slice->total) }}
        @if ($slice->isPaged()) · page {{ number_format($slice->page) }} of {{ number_format($slice->lastPage()) }} @endif
    </span>
    <form method="get" action="{{ $query->path }}" class="inline">
        @foreach ($query->hiddenFields() as $field)
            @continue ($field['name'] === ($query->qualifier === '' ? 'size' : $query->qualifier.'_size'))
            <input type="hidden" name="{{ $field['name'] }}" value="{{ $field['value'] }}">
        @endforeach
        @if ($query->search !== null)<input type="hidden" name="{{ $query->qualifier === '' ? 'q' : $query->qualifier.'_q' }}" value="{{ $query->search }}">@endif
        <label class="sizer">
            <span>Rows</span>
            <select name="{{ $query->qualifier === '' ? 'size' : $query->qualifier.'_size' }}" onchange="this.form.submit()" aria-label="Rows per page">
                @foreach ($query->settings->pageSizes as $size)
                    <option value="{{ $size }}" @selected($query->size === $size)>{{ $size }}</option>
                @endforeach
            </select>
        </label>
        <button class="act" type="submit">Apply</button>
    </form>
    <span class="spacer"></span>
    @if ($slice->isPaged())
        <a class="{{ $slice->hasPrevious() ? 'act' : 'act off' }}"
           @if ($slice->hasPrevious()) href="{{ $slice->link($slice->page - 1) }}" @endif>Previous</a>
        @if ($window[0] > 1)
            <a class="act" href="{{ $slice->link(1) }}">1</a>
            @if ($window[0] > 2)<span class="gap">…</span>@endif
        @endif
        @foreach ($window as $n)
            <a class="{{ $n === $slice->page ? 'act on' : 'act' }}" href="{{ $slice->link($n) }}">{{ $n }}</a>
        @endforeach
        @if (end($window) < $slice->lastPage())
            @if (end($window) < $slice->lastPage() - 1)<span class="gap">…</span>@endif
            <a class="act" href="{{ $slice->link($slice->lastPage()) }}">{{ number_format($slice->lastPage()) }}</a>
        @endif
        <a class="{{ $slice->hasNext() ? 'act' : 'act off' }}"
           @if ($slice->hasNext()) href="{{ $slice->link($slice->page + 1) }}" @endif>Next</a>
    @endif
</div>
