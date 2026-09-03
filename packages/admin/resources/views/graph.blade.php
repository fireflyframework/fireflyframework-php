@extends('firefly-admin::layout')
@section('title', 'Bean graph')
@section('body')
    @php
        use Firefly\Admin\BeanGraph;

        // Group by level for the layered drawing. Levels come from the model; the view only positions.
        $byLevel = [];
        foreach ($graph->nodes as $node) { $byLevel[$node['level']][] = $node; }
        ksort($byLevel);

        $nodeW = 186; $nodeH = 42; $gapX = 22; $gapY = 74;
        $widest = 0;
        foreach ($byLevel as $row) { $widest = max($widest, count($row)); }
        $canvasW = max(720, $widest * ($nodeW + $gapX));
        $canvasH = max(240, count($byLevel) * ($nodeH + $gapY));

        // Centre each level, then remember every node's box so the edges can be drawn between them.
        $at = [];
        foreach ($byLevel as $level => $row) {
            $rowW = count($row) * ($nodeW + $gapX) - $gapX;
            $startX = ($canvasW - $rowW) / 2;
            foreach (array_values($row) as $i => $node) {
                $at[$node['id']] = [
                    'x' => $startX + $i * ($nodeW + $gapX),
                    'y' => $level * ($nodeH + $gapY) + 16,
                ];
            }
        }
    @endphp

    <div class="head">
        <h1>Bean graph</h1>
        <p>How your beans depend on one another. A constructor asks for a <em>type</em>, so an edge through an
           interface is drawn to the bean that actually implements it and labelled with the interface.</p>
    </div>

    <dl class="stats">
        <div class="stat"><dt>Beans</dt><dd>{{ count($graph->nodes) }}</dd></div>
        <div class="stat"><dt>Relations</dt><dd>{{ count($graph->edges) }}</dd></div>
        <div class="stat"><dt>Layers</dt><dd>{{ count($byLevel) }}</dd></div>
        <div class="stat">
            <dt>Cycles</dt>
            <dd>@if ($graph->cycles === []){{ 0 }}@else<span class="chip down">{{ count($graph->cycles) }}</span>@endif</dd>
        </div>
        <div class="stat"><dt>Unresolved</dt><dd>{{ count($graph->unresolved) }}</dd></div>
    </dl>

    @if ($graph->cycles !== [])
        <div class="panel" style="margin-top:16px">
            @include('firefly-admin::_panel-head', ['title' => 'Circular dependencies', 'count' => count($graph->cycles)])
            <div class="tw">
                <table>
                    <thead><tr><th>From</th><th>Depends on</th></tr></thead>
                    <tbody>
                    @foreach ($graph->cycles as $cycle)
                        <tr>
                            <td class="mono">{{ Firefly\Admin\Format::shortClass($cycle['from']) }}</td>
                            <td class="mono">{{ Firefly\Admin\Format::shortClass($cycle['to']) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <p class="note" style="padding:0 14px 12px;margin:0">The container has no cycle detection, so a
            cycle among eager singletons exhausts memory at boot rather than reporting itself. Break one of
            these edges — usually by injecting an interface and letting the other side depend on that.</p>
        </div>
    @endif

    <div class="panel" style="margin-top:16px">
        @include('firefly-admin::_panel-head', [
            'title' => 'Wiring', 'count' => count($graph->nodes).' beans',
            'filter' => 'graph-body', 'placeholder' => 'Highlight a bean…',
        ])

        @if ($graph->nodes === [])
            @include('firefly-admin::_empty', [
                'title' => 'No beans to graph',
                'body' => 'Check <code>firefly.scan.paths</code> points at your application namespace.',
            ])
        @elseif (count($graph->nodes) > $settings->graphMaxNodes)
            @include('firefly-admin::_empty', [
                'title' => 'Too many beans to draw at once',
                'body' => 'This application has '.count($graph->nodes).' beans. A diagram past '.$settings->graphMaxNodes.' nodes is a hairball rather than something you can read, so the relations are listed below instead. Raise <code>firefly.admin.graph.max-nodes</code> to draw it anyway.',
            ])
        @else
            <div class="canvas">
                <svg viewBox="0 0 {{ (int) $canvasW }} {{ (int) $canvasH }}" width="{{ (int) $canvasW }}" height="{{ (int) $canvasH }}"
                     role="img" aria-label="Bean dependency graph, {{ count($graph->nodes) }} beans and {{ count($graph->edges) }} relations">
                    <defs>
                        <marker id="arrow" viewBox="0 0 8 8" refX="7" refY="4" markerWidth="7" markerHeight="7" orient="auto-start-reverse">
                            <path d="M0,0 L8,4 L0,8 z" fill="currentColor"/>
                        </marker>
                    </defs>

                    <g class="edges">
                        @foreach ($graph->edges as $edge)
                            @continue (! isset($at[$edge['from']], $at[$edge['to']]))
                            @php
                                $a = $at[$edge['from']]; $b = $at[$edge['to']];
                                $x1 = $a['x'] + $nodeW / 2; $y1 = $a['y'] + $nodeH;
                                $x2 = $b['x'] + $nodeW / 2; $y2 = $b['y'];
                                $mid = ($y1 + $y2) / 2;
                            @endphp
                            <path class="edge {{ $edge['via'] !== null ? 'via' : '' }}"
                                  data-from="{{ $edge['from'] }}" data-to="{{ $edge['to'] }}"
                                  d="M{{ round($x1, 1) }},{{ round($y1, 1) }} C{{ round($x1, 1) }},{{ round($mid, 1) }} {{ round($x2, 1) }},{{ round($mid, 1) }} {{ round($x2, 1) }},{{ round($y2, 1) }}"
                                  marker-end="url(#arrow)">
                                @if ($edge['via'] !== null)
                                    <title>via {{ $edge['via'] }}</title>
                                @endif
                            </path>
                        @endforeach
                    </g>

                    <g class="nodes">
                        @foreach ($graph->nodes as $node)
                            @php $pos = $at[$node['id']]; @endphp
                            <g class="node" data-id="{{ $node['id'] }}" data-search="{{ strtolower($node['id']) }}"
                               transform="translate({{ round($pos['x'], 1) }},{{ round($pos['y'], 1) }})">
                                <title>{{ $node['id'] }} — {{ $node['stereotype'] }}, {{ $node['scope'] }} · {{ $node['in'] }} in, {{ $node['out'] }} out</title>
                                <rect width="{{ $nodeW }}" height="{{ $nodeH }}" rx="7"/>
                                <text x="10" y="17">{{ \Illuminate\Support\Str::limit($node['label'], 24) }}</text>
                                <text x="10" y="31" class="sub">{{ $node['stereotype'] }} · {{ $node['in'] }}&#8593; {{ $node['out'] }}&#8595;</text>
                            </g>
                        @endforeach
                    </g>
                </svg>
            </div>
        @endif
    </div>

    <div class="panel">
        @include('firefly-admin::_panel-head', [
            'title' => 'Relations', 'count' => count($graph->edges),
            'filter' => 'edges-body', 'placeholder' => 'Filter relations…',
        ])
        @if ($graph->edges === [])
            @include('firefly-admin::_empty', [
                'title' => 'No relations found',
                'body' => 'Every bean here is constructed without depending on another bean. Constructor parameters typed as scalars are configuration, not wiring, and are deliberately not edges.',
            ])
        @else
            <div class="tw">
                <table>
                    <thead><tr><th>Bean</th><th>Depends on</th><th>Wired by</th></tr></thead>
                    <tbody id="edges-body">
                    @foreach ($graph->edges as $edge)
                        <tr>
                            <td class="cls"><span class="nm">{{ Firefly\Admin\Format::shortClass($edge['from']) }}</span><span class="ns">{{ rtrim(Firefly\Admin\Format::namespaceOf($edge['from']), '\\') }}</span></td>
                            <td class="cls"><span class="nm">{{ Firefly\Admin\Format::shortClass($edge['to']) }}</span><span class="ns">{{ rtrim(Firefly\Admin\Format::namespaceOf($edge['to']), '\\') }}</span></td>
                            <td class="mono dim tight">{{ $edge['via'] !== null ? Firefly\Admin\Format::shortClass($edge['via']) : 'class' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    @if ($graph->unresolved !== [])
        <div class="panel">
            @include('firefly-admin::_panel-head', ['title' => 'Provided outside the container', 'count' => count($graph->unresolved)])
            <div style="padding:12px 14px;display:flex;flex-wrap:wrap;gap:6px">
                @foreach ($graph->unresolved as $type)
                    <span class="chip flat" title="{{ $type }}">{{ Firefly\Admin\Format::shortClass($type) }}</span>
                @endforeach
            </div>
            <p class="note" style="padding:0 14px 12px;margin:0">These constructor types are satisfied by a
            Laravel container binding rather than a scanned bean — the request, the config repository, a
            connection — so they are not drawn as nodes.</p>
        </div>
    @endif
@endsection
