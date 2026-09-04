@extends('firefly-admin::layout')
@section('title', 'Entity map')
@section('body')
    @php
        use Firefly\Admin\Format;

        // LAYOUT. Boxes on a grid, one row per level, centred within the widest row. An entity box has to
        // show its columns, so its height is content-driven and the row height is the tallest box in it —
        // a fixed height would clip a wide table or leave a lake of whitespace under a narrow one.
        $boxW = 236;
        $gapX = 54;
        $gapY = 96;
        $headH = 42;
        $rowH = 17;
        $padY = 10;

        $byLevel = [];
        foreach ($map->nodes as $node) { $byLevel[$node['level']][] = $node; }
        ksort($byLevel);

        $height = static fn (array $n): int => $headH + $padY + $rowH * (count($n['columns']) + ($n['more'] > 0 ? 1 : 0));

        $widest = 0;
        foreach ($byLevel as $row) { $widest = max($widest, count($row)); }
        $canvasW = max(1, $widest) * $boxW + max(0, $widest - 1) * $gapX + 40;

        $placed = [];
        $y = 20;
        foreach ($byLevel as $row) {
            $rowW = count($row) * $boxW + (count($row) - 1) * $gapX;
            $x = (int) (($canvasW - $rowW) / 2);
            $tallest = 0;

            foreach ($row as $node) {
                $h = $height($node);
                $tallest = max($tallest, $h);
                $placed[$node['slug']] = ['x' => $x, 'y' => $y, 'w' => $boxW, 'h' => $h, 'node' => $node];
                $x += $boxW + $gapX;
            }

            $y += $tallest + $gapY;
        }
        $canvasH = $y;
    @endphp

    <div class="head">
        <h1>Entity map</h1>
        <p>Every browsable entity and the foreign keys between them, from the same discovery the data browser
           walks. A hasMany and the belongsTo facing it are one key seen from two ends, so each is drawn once,
           pointing from the table that <em>holds</em> the key to the table it references.</p>
    </div>

    <dl class="stats">
        <div class="stat"><dt>Entities</dt><dd>{{ count($map->nodes) }}</dd></div>
        <div class="stat"><dt>Foreign keys</dt><dd>{{ count($map->edges) }}</dd></div>
        <div class="stat"><dt>Levels</dt><dd>{{ count($map->levelsPresent()) }}</dd></div>
        <div class="stat"><dt>Cycles</dt><dd class="{{ $map->cycles === [] ? '' : 'bad' }}">{{ count($map->cycles) }}</dd></div>
    </dl>

    @if ($map->isEmpty())
        <div class="panel">
            @include('firefly-admin::_empty', [
                'title' => 'No entities to map',
                'body' => 'No bean implements <code>CrudRepository</code>, so there is nothing to draw. Declare a
                           repository — <code>extends EloquentRepository</code> plus a model name — and it
                           appears here and in the browser at the same time.',
            ])
        </div>
    @else
        <div class="panel">
            <header>
                <h2>Schema</h2>
                <span class="spacer"></span>
                <span class="meta">{{ count($map->nodes) }} entities · {{ count($map->edges) }} keys</span>
            </header>
            <div class="mapwrap">
                <svg class="emap" role="img" aria-label="Entity relationship diagram"
                     width="{{ $canvasW }}" height="{{ $canvasH }}" viewBox="0 0 {{ $canvasW }} {{ $canvasH }}">
                    <defs>
                        <marker id="emarw" viewBox="0 0 8 8" refX="7" refY="4" markerWidth="6" markerHeight="6" orient="auto-start-reverse">
                            <path d="M0,0 L8,4 L0,8 z" fill="currentColor"/>
                        </marker>
                    </defs>

                    @foreach ($map->edges as $edge)
                        @php
                            $a = $placed[$edge['from']] ?? null;
                            $b = $placed[$edge['to']] ?? null;
                        @endphp
                        @continue ($a === null || $b === null)
                        @php
                            // Leave from the bottom of the holder and arrive at the top of the referenced
                            // table when they are on different rows; side-to-side when they share one, which
                            // is what a self-reference and a same-level pair both need.
                            $sameRow = $a['y'] === $b['y'];
                            $x1 = $a['x'] + $a['w'] / 2;
                            $y1 = $sameRow ? $a['y'] + $a['h'] / 2 : $a['y'] + $a['h'];
                            $x2 = $b['x'] + $b['w'] / 2;
                            $y2 = $sameRow ? $b['y'] + $b['h'] / 2 : $b['y'];
                            $mid = $sameRow ? ($y1 - 40) : ($y1 + $y2) / 2;
                            $d = $sameRow
                                ? sprintf('M%d,%d C%d,%d %d,%d %d,%d', $x1, $y1, $x1, $mid, $x2, $mid, $x2, $y2)
                                : sprintf('M%d,%d C%d,%d %d,%d %d,%d', $x1, $y1, $x1, $mid, $x2, $mid, $x2, $y2);
                        @endphp
                        <g class="ed">
                            <path class="eline" d="{{ $d }}" marker-end="url(#emarw)"/>
                            <text class="elabel" x="{{ (int) (($x1 + $x2) / 2) }}" y="{{ (int) $mid }}" text-anchor="middle">{{ $edge['column'] }}</text>
                        </g>
                    @endforeach

                    @foreach ($placed as $slug => $box)
                        @php $node = $box['node']; @endphp
                        <a href="{{ $settings->url('data') }}?resource={{ urlencode($slug) }}">
                            <g class="ent" transform="translate({{ $box['x'] }},{{ $box['y'] }})">
                                <rect class="ebox" width="{{ $box['w'] }}" height="{{ $box['h'] }}" rx="8"/>
                                <rect class="ehead" width="{{ $box['w'] }}" height="{{ $headH }}" rx="8"/>
                                <text class="ename" x="12" y="18">{{ $node['label'] }}</text>
                                <text class="etable" x="12" y="32">{{ $node['table'] ?: Format::shortClass($node['entity']) }}</text>
                                @foreach ($node['columns'] as $i => $column)
                                    <text class="ecol {{ $column['identifier'] ? 'key' : '' }}"
                                          x="12" y="{{ $headH + $padY + $rowH * $i + 4 }}">{{ $column['identifier'] ? '● ' : '' }}{{ $column['name'] }}</text>
                                    <text class="etype" x="{{ $box['w'] - 12 }}" y="{{ $headH + $padY + $rowH * $i + 4 }}" text-anchor="end">{{ $column['type'] }}</text>
                                @endforeach
                                @if ($node['more'] > 0)
                                    <text class="emore" x="12" y="{{ $headH + $padY + $rowH * count($node['columns']) + 4 }}">+{{ $node['more'] }} more</text>
                                @endif
                            </g>
                        </a>
                    @endforeach
                </svg>
            </div>
            <p class="note">
                A box is a link: open it to browse that entity's records. Columns are the first
                {{ 8 }} the schema reports, with the identifier marked; a key's own name is drawn on the line
                it belongs to. Relations the browser cannot express as one column — a pivot, a polymorphic
                type column — are listed on each record page but are not drawn here, because a line with no
                join to name would be decoration.
            </p>
        </div>

        @if ($map->cycles !== [])
            <div class="panel">
                @include('firefly-admin::_panel-head', ['title' => 'Cycles', 'count' => count($map->cycles)])
                <div class="tw">
                    <table>
                        <thead><tr><th>From</th><th>To</th></tr></thead>
                        <tbody>
                        @foreach ($map->cycles as $cycle)
                            <tr><td class="mono">{{ $cycle['from'] }}</td><td class="mono">{{ $cycle['to'] }}</td></tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="note">Two tables that reference each other. Legal, and usually a nullable key on one
                   side — but worth knowing about, because it is also what makes a delete order ambiguous.</p>
            </div>
        @endif
    @endif
@endsection
