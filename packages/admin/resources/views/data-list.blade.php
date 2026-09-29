@extends('firefly-admin::layout')
@section('title', 'Browse data')
@section('body')
    @php
        use Firefly\Admin\Format;

        $resource = $listing->resource;
        $schema = $listing->schema;
        $columns = $listing->columns();
        $identifier = $schema?->identifierColumn();

        // $base names a RECORD, not a listing: `&id=` and `&new=1` leave the table rather than moving
        // within it, so they carry the resource and nothing else. Every link that MOVES within the listing
        // goes through $query->link(), which is why the four "do not forget to carry this" strings that
        // used to live here are gone.
        $base = $settings->url('data').'?resource='.urlencode($resource?->slug ?? '');

        // THE TWO LINKS THAT MUST SHED A PARAMETER, WHICH IS WHY THEY CANNOT COME FROM link(). `link()`
        // re-emits everything the query CARRIES, and the applied filters are exactly that — so a "clear"
        // built from it would hand back the view it was meant to leave. They are built from $base instead,
        // plus `own()`: the listing's own position, which is what a clear must KEEP. Dropping it is the bug
        // this page had — set Rows to 100, apply a filter, clear it, and the table silently snapped back to
        // the configured default under a reader who had chosen otherwise. `page` is dropped on purpose:
        // widening a result set invalidates the offset into it, so both links return to the top.
        //
        // They differ in one parameter. The filter controls SHED THE FILTERS and nothing else — a search
        // term survives clearing a condition, the same way the filter bar re-submits `q` as a hidden field
        // so that applying one keeps the search. The empty state's "clear them all" answers a question the
        // reader is asking about every narrowing at once, so it sheds the search as well — and that link is
        // the only way back for a resource with no searchable column, where a hand-edited `?q=` renders no
        // search box to clear it from.
        $own = $query->own();
        unset($own['page']);
        $withoutSearch = $own;
        unset($withoutSearch['q']);
        $clearFilters = $base.($own === [] ? '' : '&'.http_build_query($own));
        $clearAll = $base.($withoutSearch === [] ? '' : '&'.http_build_query($withoutSearch));
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
        {{-- Beside the title rather than inside the panel header: that header is now the shared
             _panel-head, whose contract is a title, a count and a search form — and a "New record" link is
             none of those. It is the one control on this page that creates something, so it reads better at
             the top anyway. --}}
        @if ($writable && $resource?->isEloquentBacked())
            <a class="act" href="{{ $base }}&new=1">New record</a>
        @endif
    </div>

    @if ($listing->filters !== [])
        <p class="tip">
            Showing only rows where
            @foreach ($listing->filters as $filter)
                <code>{{ $filter->describe() }}</code>@if (! $loop->last) and @endif
            @endforeach
            · <a href="{{ $clearFilters }}">clear</a>
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
            <form method="get" action="{{ $query->path }}" class="filterform">
                {{-- The resource, the ordering and the size, from the listing that produced this page. The
                     `f…` parameters are skipped because this form's own rows ARE the filter: re-submitting
                     the applied one as a hidden field would double every condition. `q` is not among what
                     hiddenFields() emits — that method is written for the search form, whose <input> owns
                     the name — so this form, which has no such input, re-adds it by hand. Without it,
                     applying a filter from inside a searched listing silently widens it to the whole
                     table. --}}
                @foreach ($query->hiddenFields() as $field)
                    @continue (str_starts_with($field['name'], 'f'))
                    <input type="hidden" name="{{ $field['name'] }}" value="{{ $field['value'] }}">
                @endforeach
                @if ($query->search !== null)<input type="hidden" name="q" value="{{ $query->search }}">@endif

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
                        <a class="act" href="{{ $clearFilters }}">Clear</a>
                    @endif
                    <span class="hint">Conditions are combined with <strong>and</strong>.</span>
                </div>
            </form>
        </details>

        <div class="panel">
            {{-- The same header every other listing on the dashboard draws. Its search form re-submits the
                 resource, the ordering, the size and the filters through hiddenFields(), so searching
                 inside a filtered listing NARROWS it instead of silently widening to the whole table; and
                 the count is the formatted grand total followed by the word `total`, the wording this page
                 hand-rolled and tests/Browser/AdminDataBrowserTest.php reads as `3 total` / `1 total`.

                 `searchable` IS THE ONE THING THIS PAGE HAS TO TELL THE PARTIAL. Every other listing on the
                 dashboard searches the strings it renders; a resource does not necessarily have any.
                 `DataSchema::searchable()` keeps only non-sensitive string columns, so a join table of
                 `id`, `order_id`, `quantity` — or one whose only text column is masked — publishes none,
                 and `DataQueryEngine` answers `[[], 0]` for every term the moment that list is empty. The
                 box is therefore drawn only where it can match, which is the guard the <header> this
                 include replaced had and the reason the count is passed through the same branch. --}}
            @include('firefly-admin::_panel-head', [
                'title' => 'Records', 'count' => $listing->total, 'query' => $query,
                'searchable' => $schema->searchable() !== [],
                'placeholder' => 'Search records…',
            ])

            @if ($listing->isEmpty())
                @include('firefly-admin::_empty', [
                    'title' => $listing->search !== null || $listing->filters !== [] ? 'Nothing matches' : 'No records yet',
                    'body' => $listing->search !== null || $listing->filters !== []
                        ? 'Loosen a condition, or <a href="'.e($clearAll).'">clear them all</a>.'
                        : 'This resource has no rows.',
                ])
            @else
                <div class="tw">
                    {{-- `ftable` FIRST, `datatable` BESIDE IT. The shared class is what brings the fixed
                         layout and the scrollport every other listing has; the second one keeps the
                         DATABASE's own type classes — int, float, bool, datetime, string, json — which are
                         a fact about the resource DataSchema derived rather than a presentation choice this
                         view makes, and which no other page has. --}}
                    <table class="ftable datatable">
                        {{-- THE SAME COLGROUP THE SHARED `_table-head` EMITS, off the same TableView — see
                             AdminAction::dataTableView(), which maps each DataColumn onto the TableColumn
                             kind that already knows how wide it is, and widens the rigid ones until the
                             humanised header fits too. What this page cannot take from that partial is the
                             <thead> under it: the `t-<dbtype>` classes below are the DATABASE's types, a
                             fact about the resource DataSchema derived, and ColumnKind models presentation
                             kinds rather than types. So the widths are shared and the header row is not. --}}
                        <colgroup>
                            @foreach ($view->widths() as $width)<col style="width:{{ $width }}">@endforeach
                        </colgroup>
                        <thead>
                        <tr>
                            @foreach ($columns as $column)
                                {{-- sortable() returns column NAMES, not DataColumn objects.

                                     `title` BECAUSE THE WIDTH THAT HOLDS THIS LABEL IS AN ESTIMATE. PHP
                                     cannot measure a font, so the column was widened from a character
                                     count (TableColumn::HEADER_CH_PER_CHARACTER) and a label of unusually
                                     wide glyphs can still outrun it — at which point `overflow:hidden`
                                     takes the tail off in silence. The full label on hover is what `_cell`
                                     already gives a clipped value. `scope="col"` is the partial's, and a
                                     header that announces itself to a screen reader on one listing should
                                     do it on all of them. --}}
                                <th class="t-{{ $column->type }} @if ($column->identifier) idcol @endif" scope="col" title="{{ $column->label() }}">
                                    @if (in_array($column->name, $schema->sortable(), true))
                                        <a href="{{ $query->sortLink($column->name) }}">
                                            {{ $column->label() }}<span class="ord">{{ $query->indicator($column->name) }}</span>
                                        </a>
                                    @else
                                        {{ $column->label() }}
                                    @endif
                                </th>
                            @endforeach
                            @if ($identifier !== null)<th scope="col"></th>@endif
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

                @include('firefly-admin::_pager', ['slice' => $slice, 'query' => $query])
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
