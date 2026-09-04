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

        // Carried onto every sort link and both pager links. A filter that survived neither would widen the
        // listing back to every row the moment someone sorted it, which reads as rows appearing from nowhere.
        $keepFilter = $listing->filter !== null ? '&'.$listing->filter->toQuery() : '';

        /**
         * Values arrive RAW: an Eloquent-backed row holds the driver's value, so a bool column can be int 1
         * and a json column a string. The column TYPE is the rendering hint — never the value's PHP type.
         */
        $render = static function (mixed $value, ?DataColumn $column): string {
            if ($value === null) { return '—'; }
            $type = $column?->type ?? DataColumn::TYPE_STRING;

            return match ($type) {
                DataColumn::TYPE_BOOL => ((int) $value) === 1 ? 'true' : 'false',
                DataColumn::TYPE_JSON => is_string($value) ? $value : (string) json_encode($value, JSON_UNESCAPED_SLASHES),
                default => is_scalar($value) ? (string) $value : (string) json_encode($value, JSON_UNESCAPED_SLASHES),
            };
        };
    @endphp

    <div class="head">
        <h1>{{ $resource?->label ?? 'Records' }}</h1>
        <p>
            @if ($resource?->entityClass !== null)<code>{{ Format::shortClass($resource->entityClass) }}</code>@endif
            @if ($resource?->table)· table <code>{{ $resource->table }}</code>@endif
            · <a href="{{ $settings->url('data') }}">all resources</a>
        </p>
    </div>

    @if ($listing->filter !== null)
        <p class="tip">
            Showing only rows where <code>{{ $listing->filter->column }}</code> is
            <code>{{ $listing->filter->value }}</code>.
            <a href="{{ $base }}">Show all {{ strtolower($resource?->label ?? 'records') }}</a>
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
        <div class="panel">
            <header>
                <h2>Records</h2>
                <span class="spacer"></span>
                @if ($schema->searchable() !== [])
                    <form method="get" action="{{ $settings->url('data') }}" style="display:flex;gap:6px">
                        <input type="hidden" name="resource" value="{{ $resource?->slug }}">
                        @if ($listing->sort)<input type="hidden" name="sort" value="{{ $listing->sort }}">@endif
                        <input type="hidden" name="dir" value="{{ $listing->direction }}">
                        {{-- Searching inside a relation's listing NARROWS it; without these the search box
                             would silently drop the relation and search the whole table. --}}
                        @if ($listing->filter !== null)
                            <input type="hidden" name="fk" value="{{ $listing->filter->column }}">
                            <input type="hidden" name="fv" value="{{ $listing->filter->value }}">
                        @endif
                        <input class="filter" type="search" name="q" value="{{ $listing->search }}" placeholder="Search…" aria-label="Search records">
                    </form>
                @endif
                <span class="meta">{{ number_format($listing->total) }} total</span>
            </header>

            @if ($listing->isEmpty())
                @include('firefly-admin::_empty', [
                    'title' => $listing->search !== null ? 'Nothing matches that search' : 'No records yet',
                    'body' => $listing->search !== null ? 'Try a shorter term, or clear the search.' : 'This resource has no rows.',
                ])
            @else
                <div class="tw">
                    <table>
                        <thead>
                        <tr>
                            @foreach ($columns as $column)
                                @php
                                    // searchable()/sortable() return column NAMES, not DataColumn objects.
                                    $sortable = in_array($column->name, $schema->sortable(), true);
                                    $isSorted = $listing->sort === $column->name;
                                    $next = $isSorted && $listing->direction === 'asc' ? 'desc' : 'asc';
                                @endphp
                                <th>
                                    @if ($sortable)
                                        <a href="{{ $base }}&sort={{ urlencode($column->name) }}&dir={{ $next }}{{ $listing->search !== null ? '&q='.urlencode($listing->search) : '' }}{{ $keepFilter }}">
                                            {{ $column->label() }}@if ($isSorted) {{ $listing->direction === 'asc' ? '↑' : '↓' }}@endif
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
                                    <td class="mono {{ $column->sensitive ? 'dim' : '' }} wrap text">
                                        {{ $render($row[$column->name] ?? null, $column) }}
                                    </td>
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

                @if ($listing->totalPages() > 1)
                    <div class="pager">
                        <span>Page {{ $listing->page }} of {{ $listing->totalPages() }}</span>
                        <span class="spacer"></span>
                        @php
                            $keep = ($listing->sort !== null ? '&sort='.urlencode($listing->sort).'&dir='.$listing->direction : '')
                                .($listing->search !== null ? '&q='.urlencode($listing->search) : '')
                                .$keepFilter;
                        @endphp
                        @if ($listing->hasPrevious())
                            <a class="act" href="{{ $base }}&page={{ $listing->page - 1 }}{{ $keep }}">Previous</a>
                        @endif
                        @if ($listing->hasNext())
                            <a class="act" href="{{ $base }}&page={{ $listing->page + 1 }}{{ $keep }}">Next</a>
                        @endif
                    </div>
                @endif
            @endif
        </div>
    @endif

    @unless ($writable)
        <p class="note">Read-only. Set <code>firefly.admin.data.writable</code> to allow edits and deletes.</p>
    @endunless
@endsection
