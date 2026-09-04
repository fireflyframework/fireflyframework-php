@extends('firefly-admin::layout')
@section('title', 'Browse data')
@section('body')
    @php
        use Firefly\Admin\Data\DataColumn;
        use Firefly\Admin\Format;

        $resource = $listing->resource;
        $schema = $listing->schema;
        $columns = $listing->columns();
        $identifier = $schema?->identifierColumn();
        $base = $settings->url('data').'?resource='.urlencode($resource?->slug ?? '');

        // Everything a link must carry to survive being clicked. A sort that dropped the filter would widen
        // the listing back to every row, which reads as rows appearing from nowhere; a filter that dropped
        // the page size would silently resize the table under the reader.
        $keepFilter = $listing->filterQuery() === '' ? '' : '&'.$listing->filterQuery();
        $keepSearch = $listing->search !== null ? '&q='.urlencode($listing->search) : '';
        $keepSize = '&size='.$listing->perPage;
        $keepSort = $listing->sort !== null ? '&sort='.urlencode($listing->sort).'&dir='.$listing->direction : '';
    @endphp

    <div class="head">
        <h1>{{ $resource?->label ?? 'Records' }}</h1>
        <p>
            @if ($resource?->entityClass !== null)<code>{{ Format::shortClass($resource->entityClass) }}</code>@endif
            @if ($resource?->table)· table <code>{{ $resource->table }}</code>@endif
            · <a href="{{ $settings->url('data') }}">all resources</a>
            @if ($relations !== [])
                · related:
                @foreach ($relations as $relation)
                    @if ($relation->navigable() && $relation->toMany === false)
                        <a href="{{ $settings->url('data') }}?resource={{ urlencode($relation->relatedSlug) }}">{{ $relation->shortRelated() }}</a>@if (! $loop->last), @endif
                    @else
                        <span class="dim">{{ $relation->shortRelated() ?: $relation->kind }}</span>@if (! $loop->last), @endif
                    @endif
                @endforeach
            @endif
        </p>
    </div>

    @if ($listing->filters !== [])
        <p class="tip">
            Showing only rows where
            @foreach ($listing->filters as $filter)
                <code>{{ $filter->describe() }}</code>@if (! $loop->last) and @endif
            @endforeach
            · <a href="{{ $base }}{{ $keepSize }}">clear</a>
        </p>
    @endif

    @if ($listing->failed())
        <div class="panel">
            @include('firefly-admin::_empty', ['title' => 'The listing failed', 'body' => e($listing->error)])
        </div>
    @elseif ($schema === null || $schema->isEmpty())
        <div class="panel">
            @include('firefly-admin::_empty', [
                'title' => 'No columns to show',
                'body' => 'The browser could not derive a column list for this resource — it has no Eloquent model with a readable table, and its entity exposes no public properties.',
            ])
        </div>
    @else

        {{-- THE FILTER BAR. One row per condition, each a column, a comparison and a value. It is a GET form,
             so every filtered view is a URL an operator can bookmark, paste into a ticket or hand to someone
             else — which is most of what a data explorer is for. --}}
        <details class="panel filters" @if ($listing->filters !== []) open @endif>
            <summary>
                <span>Filter</span>
                <span class="spacer"></span>
                <span class="meta">{{ count($listing->filters) ?: 'none' }}{{ count($listing->filters) ? ' active' : '' }}</span>
            </summary>
            <form method="get" action="{{ $settings->url('data') }}" class="filterform">
                <input type="hidden" name="resource" value="{{ $resource?->slug }}">
                @if ($listing->sort)<input type="hidden" name="sort" value="{{ $listing->sort }}">@endif
                <input type="hidden" name="dir" value="{{ $listing->direction }}">
                <input type="hidden" name="size" value="{{ $listing->perPage }}">
                @if ($listing->search !== null)<input type="hidden" name="q" value="{{ $listing->search }}">@endif

                <div id="frows">
                    @php $rows = $listing->filters; $rows[] = null; @endphp
                    @foreach ($rows as $row)
                        <div class="frow">
                            {{-- filterable(), not the whole column list: a masked column is not offered here
                                 because a filter over it answers a yes/no question about the value the page
                                 refuses to show, which repeated is an extraction oracle. DataBrowser drops
                                 one anyway; this is so the control never appears to accept it. --}}
                            <select name="fc[]" aria-label="Column">
                                <option value="">—</option>
                                @foreach ($schema->columns as $column)
                                    @continue (! in_array($column->name, $schema->filterable(), true))
                                    <option value="{{ $column->name }}" @selected($row?->column === $column->name)>{{ $column->label() }}</option>
                                @endforeach
                            </select>
                            <select name="fo[]" aria-label="Comparison">
                                @foreach ($operators as $id => $label)
                                    <option value="{{ $id }}" @selected($row?->operator === $id)>{{ $label }}</option>
                                @endforeach
                            </select>
                            <input name="fv[]" value="{{ $row?->value }}" placeholder="value" aria-label="Value">
                            <button class="drop" type="button" title="Remove this condition" aria-label="Remove this condition">&times;</button>
                        </div>
                    @endforeach
                </div>

                <div class="actions">
                    <button class="go" type="submit">Apply</button>
                    <button class="act" type="button" id="fadd">Add condition</button>
                    @if ($listing->filters !== [])
                        <a class="act" href="{{ $base }}{{ $keepSize }}">Clear</a>
                    @endif
                    <span class="hint">Conditions are combined with <strong>and</strong>.</span>
                </div>
            </form>
        </details>

        <div class="panel">
            <header>
                <h2>Records</h2>
                <span class="spacer"></span>
                @if ($schema->searchable() !== [])
                    <form method="get" action="{{ $settings->url('data') }}" class="inline">
                        <input type="hidden" name="resource" value="{{ $resource?->slug }}">
                        @if ($listing->sort)<input type="hidden" name="sort" value="{{ $listing->sort }}">@endif
                        <input type="hidden" name="dir" value="{{ $listing->direction }}">
                        <input type="hidden" name="size" value="{{ $listing->perPage }}">
                        {{-- Searching inside a filtered listing NARROWS it; without these the search box
                             would silently drop the filter and search the whole table. --}}
                        @foreach ($listing->filters as $filter)
                            <input type="hidden" name="fc[]" value="{{ $filter->column }}">
                            <input type="hidden" name="fo[]" value="{{ $filter->operator }}">
                            <input type="hidden" name="fv[]" value="{{ $filter->value }}">
                        @endforeach
                        <input class="filter" type="search" name="q" value="{{ $listing->search }}" placeholder="Search…" aria-label="Search records">
                    </form>
                @endif
                @if ($writable && $resource?->isEloquentBacked())
                    <a class="act" href="{{ $base }}&new=1">New record</a>
                @endif
                <span class="meta">{{ number_format($listing->total) }} total</span>
            </header>

            @if ($listing->isEmpty())
                @include('firefly-admin::_empty', [
                    'title' => $listing->search !== null || $listing->filters !== [] ? 'Nothing matches' : 'No records yet',
                    'body' => $listing->search !== null || $listing->filters !== []
                        ? 'Loosen a condition, or <a href="'.e($base).'">clear them all</a>.'
                        : 'This resource has no rows.',
                ])
            @else
                <div class="tw">
                    <table class="datatable">
                        <thead>
                        <tr>
                            @foreach ($columns as $column)
                                @php
                                    // searchable()/sortable() return column NAMES, not DataColumn objects.
                                    $sortable = in_array($column->name, $schema->sortable(), true);
                                    $isSorted = $listing->sort === $column->name;
                                    $next = $isSorted && $listing->direction === 'asc' ? 'desc' : 'asc';
                                @endphp
                                <th class="t-{{ $column->type }} @if ($column->identifier) idcol @endif">
                                    @if ($sortable)
                                        <a href="{{ $base }}&sort={{ urlencode($column->name) }}&dir={{ $next }}{{ $keepSearch }}{{ $keepFilter }}{{ $keepSize }}">
                                            {{ $column->label() }}<span class="ord">{{ $isSorted ? ($listing->direction === 'asc' ? '↑' : '↓') : '' }}</span>
                                        </a>
                                    @else
                                        {{ $column->label() }}
                                    @endif
                                </th>
                            @endforeach
                            @if ($identifier !== null)<th></th>@endif
                        </tr>
                        </thead>
                        <tbody>
                        @foreach ($listing->rows as $row)
                            <tr>
                                @foreach ($columns as $column)
                                    @include('firefly-admin::_cell', ['value' => $row[$column->name] ?? null, 'column' => $column, 'base' => $base])
                                @endforeach
                                @if ($identifier !== null)
                                    <td class="tight">
                                        @php $id = $row[$identifier->name] ?? null; @endphp
                                        @if ($id !== null)
                                            <a href="{{ $base }}&id={{ urlencode((string) $id) }}">Open &rarr;</a>
                                        @endif
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>

                @php
                    $keep = $keepSort.$keepSearch.$keepFilter.$keepSize;
                    $last = max(1, $listing->totalPages());
                    $from = $listing->total === 0 ? 0 : ($listing->page - 1) * $listing->perPage + 1;
                    $to = min($listing->total, $listing->page * $listing->perPage);
                    // A window around the current page. Rendering every page of a 400-page table is a
                    // pagination control nobody can use, and the ends are kept because "first" and "last" are
                    // the two jumps people actually make.
                    $window = range(max(1, $listing->page - 2), min($last, $listing->page + 2));
                @endphp
                <div class="pager">
                    <span class="range">
                        {{ number_format($from) }}–{{ number_format($to) }} of {{ number_format($listing->total) }}
                        @if ($last > 1) · page {{ $listing->page }} of {{ number_format($last) }} @endif
                    </span>
                    <form method="get" action="{{ $settings->url('data') }}" class="inline">
                        <input type="hidden" name="resource" value="{{ $resource?->slug }}">
                        @if ($listing->sort)<input type="hidden" name="sort" value="{{ $listing->sort }}">@endif
                        <input type="hidden" name="dir" value="{{ $listing->direction }}">
                        @if ($listing->search !== null)<input type="hidden" name="q" value="{{ $listing->search }}">@endif
                        @foreach ($listing->filters as $filter)
                            <input type="hidden" name="fc[]" value="{{ $filter->column }}">
                            <input type="hidden" name="fo[]" value="{{ $filter->operator }}">
                            <input type="hidden" name="fv[]" value="{{ $filter->value }}">
                        @endforeach
                        <label class="sizer">
                            <span>Rows</span>
                            <select name="size" onchange="this.form.submit()" aria-label="Rows per page">
                                @foreach ([10, 25, 50, 100, 200] as $size)
                                    <option value="{{ $size }}" @selected($listing->perPage === $size)>{{ $size }}</option>
                                @endforeach
                            </select>
                        </label>
                    </form>
                    <span class="spacer"></span>
                    @if ($last > 1)
                        <a class="act @if (! $listing->hasPrevious()) off @endif"
                           @if ($listing->hasPrevious()) href="{{ $base }}&page={{ $listing->page - 1 }}{{ $keep }}" @endif>Previous</a>
                        @if ($window[0] > 1)
                            <a class="act" href="{{ $base }}&page=1{{ $keep }}">1</a>
                            @if ($window[0] > 2)<span class="gap">…</span>@endif
                        @endif
                        @foreach ($window as $n)
                            <a class="act @if ($n === $listing->page) on @endif" href="{{ $base }}&page={{ $n }}{{ $keep }}">{{ $n }}</a>
                        @endforeach
                        @if (end($window) < $last)
                            @if (end($window) < $last - 1)<span class="gap">…</span>@endif
                            <a class="act" href="{{ $base }}&page={{ $last }}{{ $keep }}">{{ $last }}</a>
                        @endif
                        <a class="act @if (! $listing->hasNext()) off @endif"
                           @if ($listing->hasNext()) href="{{ $base }}&page={{ $listing->page + 1 }}{{ $keep }}" @endif>Next</a>
                    @endif
                </div>
            @endif
        </div>
    @endif
@endsection

@push('scripts')
<script>
    // ADDING A CONDITION MUST NOT REQUIRE SUBMITTING ONE. The bar renders the applied filters plus one empty
    // row, which meant a second condition could only be reached by applying the first — so an "A and B"
    // query took two round trips and a first result nobody wanted. Cloning the last row is the whole fix,
    // and it is progressive: with scripts off the form still works, it just offers one row at a time.
    (function () {
        var rows = document.getElementById('frows');
        var add = document.getElementById('fadd');
        if (!rows || !add) { return; }

        function blank() {
            var last = rows.lastElementChild;
            var copy = last.cloneNode(true);
            copy.querySelectorAll('select').forEach(function (select) { select.selectedIndex = 0; });
            copy.querySelectorAll('input').forEach(function (input) { input.value = ''; });

            return copy;
        }

        add.addEventListener('click', function () {
            var copy = blank();
            rows.appendChild(copy);
            var first = copy.querySelector('select');
            if (first) { first.focus(); }
        });

        // Delegated, so it also covers the rows added above. Removing the LAST row would leave nothing to
        // clone, so it is emptied in place instead — the control never leaves the form unusable.
        rows.addEventListener('click', function (event) {
            var button = event.target.closest('.drop');
            if (!button) { return; }

            var row = button.closest('.frow');
            if (rows.children.length > 1) {
                row.remove();

                return;
            }

            row.querySelectorAll('select').forEach(function (select) { select.selectedIndex = 0; });
            row.querySelectorAll('input').forEach(function (input) { input.value = ''; });
        });
    })();
</script>
@endpush
