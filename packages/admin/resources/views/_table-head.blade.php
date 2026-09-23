{{--
    A listing's <colgroup> and <thead>, emitted from its column definitions.

    EVERY CALL SITE PASSES BOTH KEYS, and `query` may be null. Blade's @include inherits the including
    view's variables, so a partial that read `$query ?? null` would silently pick up a page-level variable
    of the same name and start linking one table's headers at another table's state. Reading them bare
    means a forgotten key is an undefined-variable error in the capstone suite rather than a wrong page.

    The widths are computed, not declared here: see TableView::widths() for why a fixed layout with an
    explicit width per column is the only arrangement that survives a fully-qualified class name.
--}}
@php
    /** @var \Firefly\Admin\Table\TableView $view */
    /** @var \Firefly\Admin\Table\ListingQuery|null $query */
    $widths = $view->widths();
@endphp
<colgroup>
    @foreach ($widths as $width)<col style="width:{{ $width }}">@endforeach
</colgroup>
<thead>
<tr>
    @foreach ($view->columns as $column)
        <th class="{{ $column->cssClass() }}" scope="col">
            @if ($query !== null && $column->isSortable())
                <a href="{{ $query->sortLink($column->key) }}">{{ $column->label }}<span class="ord">{{ $query->indicator($column->key) }}</span></a>
            @else
                {{ $column->label }}
            @endif
        </th>
    @endforeach
</tr>
</thead>
