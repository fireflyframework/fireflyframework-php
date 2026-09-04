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

                @php $rows = $listing->filters; $rows[] = null; @endphp
                @foreach ($rows as $row)
                    <div class="frow">
                        <select name="fc[]" aria-label="Column">
                            <option value="">—</option>
                            @foreach ($schema->columns as $column)
                                <option value="{{ $column->name }}" @selected($row?->column === $column->name)>{{ $column->label() }}</option>
                            @endforeach
                        </select>
                        <select name="fo[]" aria-label="Comparison">
                            @foreach ($operators as $id => $label)
                                <option value="{{ $id }}" @selected($row?->operator === $id)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <input name="fv[]" value="{{ $row?->value }}" placeholder="value" aria-label="Value">
                    </div>
                @endforeach

                <div class="actions">
                    <button class="go" type="submit">Apply</button>
                    @if ($listing->filters !== [])
                        <a class="act" href="{{ $base }}{{ $keepSize }}">Clear</a>
                    @endif
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
                    <table class="grid">
                        <thead>
                        <tr>
                            @foreach ($columns as $column)
                                @php
                                    // searchable()/sortable() return column NAMES, not DataColumn objects.
                                    $sortable = in_array($column->name, $schema->sortable(), true);
                                    $isSorted = $listing->sort === $column->name;
                                    $next = $isSorted && $listing->direction === 'asc' ? 'desc' : 'asc';
                                @endphp
                                <th class="t-{{ $column->type }}">
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
                    $last = $listing->totalPages();
                    // A window around the current page. Rendering every page of a 400-page table is a
                    // pagination control nobody can use, and the ends are kept because "first" and "last" are
                    // the two jumps people actually make.
                    $window = range(max(1, $listing->page - 2), min($last, $listing->page + 2));
                @endphp
                <div class="pager">
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
                    @else
                        <span class="meta">Showing all {{ number_format($listing->total) }}</span>
                    @endif
                </div>
            @endif
        </div>
    @endif
@endsection
