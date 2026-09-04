@extends('firefly-admin::layout')
@section('title', 'Bean graph')
@section('body')
    @php
        use Firefly\Admin\BeanGraph;
        use Firefly\Admin\Format;

        $counts = $graph->kindCounts();
        $modules = $graph->modules();

        // A stable colour per module, assigned by position so the same application always draws the same
        // picture. Hues are spread around the wheel and kept away from the semantic red/green the rest of
        // the dashboard reserves for status.
        $hues = [212, 265, 28, 172, 320, 45, 190, 288, 96, 240, 12, 150];
        $moduleHue = [];
        foreach (array_values($modules) as $i => $module) {
            $moduleHue[$module] = $hues[$i % count($hues)];
        }

        $byLevel = [];
        foreach ($graph->nodes as $node) { $byLevel[$node['level']][] = $node; }
        ksort($byLevel);

        // Cluster same-module nodes within a layer so related things end up adjacent rather than scattered.
        foreach ($byLevel as $level => $row) {
            usort($row, fn (array $a, array $b): int => [BeanGraph::moduleOf($a['id']), $a['label']] <=> [BeanGraph::moduleOf($b['id']), $b['label']]);
            $byLevel[$level] = $row;
        }

        // LAYOUT. A pure layered layout is wrong for this graph: dependency depth is shallow and wide, so
        // most beans land on one or two levels and a stock skeleton produced a single row 54 nodes and
        // 9184px across — which fit() then scaled to 11%, i.e. unreadable. Each LEVEL is therefore wrapped
        // into a grid of its own, so the drawing stays a compact rectangle while arrows still read downward
        // from dependents to dependencies.
        $nodeW = 168; $nodeH = 40; $gapX = 14; $gapY = 20; $levelGap = 46;
        $perRow = max(4, (int) ceil(sqrt(max(1, count($graph->nodes)))) + 2);

        $at = [];
        $canvasW = $perRow * ($nodeW + $gapX) - $gapX + 40;
        $y = 20;

        foreach ($byLevel as $row) {
            $rows = array_chunk($row, $perRow);
            foreach ($rows as $chunk) {
                $rowW = count($chunk) * ($nodeW + $gapX) - $gapX;
                $startX = ($canvasW - $rowW) / 2;
                foreach (array_values($chunk) as $i => $node) {
                    $at[$node['id']] = ['x' => $startX + $i * ($nodeW + $gapX), 'y' => $y];
                }
                $y += $nodeH + $gapY;
            }
            $y += $levelGap - $gapY;
        }

        $canvasH = max(260, $y + 20);

    @endphp

    <div class="head">
        <h1>Bean graph</h1>
        <p>Every bean this application wired, and what each one depends on. A constructor asks for a
           <em>type</em>, so an edge through an interface is drawn to the bean that implements it and
           labelled with the interface.</p>
    </div>

    <dl class="stats">
        <div class="stat"><dt>Beans</dt><dd>{{ count($graph->nodes) }}</dd></div>
        <div class="stat"><dt>Components</dt><dd>{{ $counts[BeanGraph::KIND_COMPONENT] }}</dd></div>
        <div class="stat"><dt>#[Bean] products</dt><dd>{{ $counts[BeanGraph::KIND_BEAN] }}</dd></div>
        <div class="stat"><dt>Config DTOs</dt><dd>{{ $counts[BeanGraph::KIND_CONFIG] }}</dd></div>
        <div class="stat"><dt>Relations</dt><dd>{{ count($graph->edges) }}</dd></div>
        <div class="stat"><dt>Layers</dt><dd>{{ count($byLevel) }}</dd></div>
        <div class="stat">
            <dt>Cycles</dt>
            <dd>@if ($graph->cycles === [])0 @else<span class="chip down">{{ count($graph->cycles) }}</span>@endif</dd>
        </div>
    </dl>

    @if ($graph->cycles !== [])
        <div class="panel" style="margin-top:16px">
            @include('firefly-admin::_panel-head', ['title' => 'Circular dependencies', 'count' => count($graph->cycles)])
            <div class="tw">
                <table>
                    <thead><tr><th>Bean</th><th>Depends on</th></tr></thead>
                    <tbody>
                    @foreach ($graph->cycles as $cycle)
                        <tr>
                            <td class="mono">{{ Format::shortClass($cycle['from']) }}</td>
                            <td class="mono">{{ Format::shortClass($cycle['to']) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <p class="note" style="padding:0 14px 12px;margin:0">The container has no cycle detection, so a
            cycle among eager singletons exhausts memory at boot rather than reporting itself. Break one of
            these edges — usually by depending on an interface and letting the other side provide it.</p>
        </div>
    @endif

    <div class="panel" style="margin-top:16px">
        <header>
            <h2>Wiring</h2>
            <span class="spacer"></span>
            <input class="filter" type="search" id="graph-find" placeholder="Find a bean…" aria-label="Find a bean">
            <button class="tool" type="button" id="g-fit" title="Fit the whole graph">Fit</button>
            <button class="tool" type="button" id="g-reset" title="Clear the selection and filters">Reset</button>
        </header>

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
            <div class="legend">
                @foreach ($modules as $module)
                    <button class="mod" type="button" data-module="{{ $module }}" aria-pressed="true"
                            style="--hue:{{ $moduleHue[$module] }}">
                        <i></i>{{ $module }}
                    </button>
                @endforeach
            </div>

            <div class="graph">
                <div class="canvas" id="g-canvas">
                    <svg id="g-svg" role="img"
                         aria-label="Bean dependency graph: {{ count($graph->nodes) }} beans, {{ count($graph->edges) }} relations">
                        <defs>
                            <marker id="arw" viewBox="0 0 8 8" refX="7" refY="4" markerWidth="6" markerHeight="6" orient="auto-start-reverse">
                                <path d="M0,0 L8,4 L0,8 z" fill="currentColor"/>
                            </marker>
                        </defs>
                        <g id="g-pan">
                            <g class="edges">
                                @foreach ($graph->edges as $edge)
                                    @continue (! isset($at[$edge['from']], $at[$edge['to']]))
                                    @php
                                        $a = $at[$edge['from']]; $b = $at[$edge['to']];
                                        $x1 = $a['x'] + $nodeW / 2; $y1 = $a['y'] + $nodeH;
                                        $x2 = $b['x'] + $nodeW / 2; $y2 = $b['y'];
                                        $mid = ($y1 + $y2) / 2;
                                    @endphp
                                    <path class="edge {{ $edge['type'] }}{{ $edge['via'] !== null ? ' via' : '' }}"
                                          data-from="{{ $edge['from'] }}" data-to="{{ $edge['to'] }}"
                                          d="M{{ round($x1, 1) }},{{ round($y1, 1) }} C{{ round($x1, 1) }},{{ round($mid, 1) }} {{ round($x2, 1) }},{{ round($mid, 1) }} {{ round($x2, 1) }},{{ round($y2, 1) }}"
                                          marker-end="url(#arw)">
                                        <title>{{ Format::shortClass($edge['from']) }} → {{ Format::shortClass($edge['to']) }}{{ $edge['via'] !== null ? ' (via '.Format::shortClass($edge['via']).')' : '' }}</title>
                                    </path>
                                @endforeach
                            </g>
                            <g class="nodes">
                                @foreach ($graph->nodes as $node)
                                    @php
                                        $pos = $at[$node['id']];
                                        $module = BeanGraph::moduleOf($node['id']);
                                    @endphp
                                    <g class="node k-{{ $node['kind'] }}" data-id="{{ $node['id'] }}"
                                       data-module="{{ $module }}" data-search="{{ strtolower($node['id'].' '.$node['detail']) }}"
                                       style="--hue:{{ $moduleHue[$module] ?? 212 }}"
                                       transform="translate({{ round($pos['x'], 1) }},{{ round($pos['y'], 1) }})" tabindex="0">
                                        <title>{{ $node['id'] }}{{ $node['detail'] !== '' ? ' — '.$node['detail'] : '' }}</title>
                                        <rect width="{{ $nodeW }}" height="{{ $nodeH }}" rx="6"/>
                                        <rect class="stripe" width="3" height="{{ $nodeH }}" rx="1.5"/>
                                        <text x="11" y="16">{{ \Illuminate\Support\Str::limit($node['label'], 22) }}</text>
                                        <text x="11" y="29" class="sub">{{ $node['kind'] }} · {{ $node['in'] }}&#8593; {{ $node['out'] }}&#8595;</text>
                                    </g>
                                @endforeach
                            </g>
                        </g>
                    </svg>
                    <div class="hint-bar" id="g-hint">Drag to pan · scroll to zoom · click a bean to focus it</div>
                </div>

                <aside class="inspect" id="g-inspect">
                    <div class="blank">Select a bean to see what it depends on and what depends on it.</div>
                </aside>
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
                'body' => 'Every bean here is built without depending on another. Constructor parameters typed as scalars are configuration, not wiring, and are deliberately not edges.',
            ])
        @else
            <div class="tw">
                <table>
                    <thead><tr><th>Bean</th><th></th><th>Depends on</th><th>Wired by</th></tr></thead>
                    <tbody id="edges-body">
                    @foreach ($graph->edges as $edge)
                        <tr>
                            <td class="cls"><span class="nm">{{ Format::shortClass($edge['from']) }}</span><span class="ns">{{ rtrim(Format::namespaceOf($edge['from']), '\\') }}</span></td>
                            <td class="tight dim mono" style="font-size:11px">{{ $edge['type'] === 'produces' ? 'produces' : '→' }}</td>
                            <td class="cls"><span class="nm">{{ Format::shortClass($edge['to']) }}</span><span class="ns">{{ rtrim(Format::namespaceOf($edge['to']), '\\') }}</span></td>
                            <td class="mono dim tight">{{ $edge['via'] !== null ? Format::shortClass($edge['via']) : '—' }}</td>
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
                    <span class="chip flat" title="{{ $type }}">{{ Format::shortClass($type) }}</span>
                @endforeach
            </div>
            <p class="note" style="padding:0 14px 12px;margin:0">These constructor types are satisfied by a
            Laravel container binding rather than a scanned bean — the request, the config repository, a
            database connection — so they are not drawn as nodes.</p>
        </div>
    @endif

    @push('scripts')
        <script>
            (function () {
                var svg = document.getElementById('g-svg');
                if (!svg) { return; }

                var pan = document.getElementById('g-pan');
                var canvas = document.getElementById('g-canvas');
                var inspect = document.getElementById('g-inspect');
                var nodes = Array.prototype.slice.call(svg.querySelectorAll('.node'));
                var edges = Array.prototype.slice.call(svg.querySelectorAll('.edge'));

                // ── pan and zoom ──────────────────────────────────────────────────────────────────────
                // A graph this size does not fit a panel at a readable node size, so the canvas is a
                // viewport over it rather than a fixed picture. Transform on a <g> instead of the viewBox:
                // the browser composites it, so dragging stays smooth with a few hundred nodes.
                var view = { x: 0, y: 0, k: 1 };

                function apply() {
                    pan.setAttribute('transform', 'translate(' + view.x + ',' + view.y + ') scale(' + view.k + ')');
                }

                // One user unit is one CSS pixel, so fit() reasons in the same units the labels are sized in.
                // The floor matters: fitting 84 nodes into a panel exactly would land around 0.45, where an
                // 11px label is 5px and nobody can read the picture they were shown. Below the floor the
                // graph opens readable and pans, which is the right default for an explorer.
                var MIN_FIT = 0.55;

                function fit() {
                    var box = pan.getBBox();
                    var w = svg.clientWidth, h = svg.clientHeight;
                    if (!box.width || !box.height || !w || !h) { return; }

                    var pad = 20;
                    view.k = Math.max(MIN_FIT, Math.min((w - pad * 2) / box.width, (h - pad * 2) / box.height, 1.3));
                    view.x = (w - box.width * view.k) / 2 - box.x * view.k;
                    view.y = (h - box.height * view.k) / 2 - box.y * view.k;
                    apply();
                }

                var dragging = false, startX = 0, startY = 0;
                canvas.addEventListener('pointerdown', function (event) {
                    if (event.target.closest('.node')) { return; }
                    dragging = true;
                    startX = event.clientX - view.x;
                    startY = event.clientY - view.y;
                    canvas.setPointerCapture(event.pointerId);
                    canvas.classList.add('grabbing');
                });
                canvas.addEventListener('pointermove', function (event) {
                    if (!dragging) { return; }
                    view.x = event.clientX - startX;
                    view.y = event.clientY - startY;
                    apply();
                });
                ['pointerup', 'pointercancel'].forEach(function (name) {
                    canvas.addEventListener(name, function () { dragging = false; canvas.classList.remove('grabbing'); });
                });
                canvas.addEventListener('wheel', function (event) {
                    event.preventDefault();
                    var rect = svg.getBoundingClientRect();
                    var mx = event.clientX - rect.left, my = event.clientY - rect.top;
                    var next = Math.min(3, Math.max(0.15, view.k * (event.deltaY < 0 ? 1.12 : 1 / 1.12)));
                    // Keep the point under the cursor fixed, which is what makes wheel-zoom feel right.
                    view.x = mx - (mx - view.x) * (next / view.k);
                    view.y = my - (my - view.y) * (next / view.k);
                    view.k = next;
                    apply();
                }, { passive: false });

                // ── selection ─────────────────────────────────────────────────────────────────────────
                var byId = {};
                nodes.forEach(function (n) { byId[n.getAttribute('data-id')] = n; });

                function esc(v) {
                    return String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                }

                // A PHP namespace separator is ONE backslash. Written as '\\' here because this block is
                // JavaScript source: the escape is the JS one, not Blade's.
                function shortOf(id) {
                    var at = id.lastIndexOf('\\');
                    return at === -1 ? id : id.slice(at + 1);
                }

                function clear() {
                    nodes.forEach(function (n) { n.classList.remove('lit', 'dimmed', 'picked'); });
                    edges.forEach(function (e) { e.classList.remove('lit', 'dimmed'); });
                    inspect.innerHTML = '<div class="blank">Select a bean to see what it depends on and what depends on it.</div>';
                }

                function focus(id) {
                    var touched = {}; touched[id] = true;
                    var out = [], incoming = [];

                    edges.forEach(function (edge) {
                        var from = edge.getAttribute('data-from'), to = edge.getAttribute('data-to');
                        if (from === id) { out.push(to); touched[to] = true; }
                        else if (to === id) { incoming.push(from); touched[from] = true; }
                    });

                    edges.forEach(function (edge) {
                        var hit = edge.getAttribute('data-from') === id || edge.getAttribute('data-to') === id;
                        edge.classList.toggle('lit', hit);
                        edge.classList.toggle('dimmed', !hit);
                    });
                    nodes.forEach(function (node) {
                        var nid = node.getAttribute('data-id');
                        node.classList.toggle('lit', !!touched[nid]);
                        node.classList.toggle('dimmed', !touched[nid]);
                        node.classList.toggle('picked', nid === id);
                    });

                    function list(title, ids) {
                        if (!ids.length) { return '<h4>' + title + '</h4><p class="none">none</p>'; }
                        return '<h4>' + title + ' <span>' + ids.length + '</span></h4><ul>' + ids.map(function (x) {
                            return '<li><button type="button" data-goto="' + esc(x) + '">' + esc(shortOf(x)) + '</button></li>';
                        }).join('') + '</ul>';
                    }

                    var node = byId[id];
                    inspect.innerHTML =
                        '<div class="who"><strong>' + esc(shortOf(id)) + '</strong><code>' + esc(id) + '</code>'
                        + (node && node.querySelector('title') ? '' : '') + '</div>'
                        + list('Depends on', out) + list('Depended on by', incoming);

                    inspect.querySelectorAll('[data-goto]').forEach(function (button) {
                        button.addEventListener('click', function () { select(button.getAttribute('data-goto')); });
                    });
                }

                var picked = null;
                function select(id) {
                    picked = (picked === id) ? null : id;
                    picked ? focus(picked) : clear();
                }

                nodes.forEach(function (node) {
                    var id = node.getAttribute('data-id');
                    node.addEventListener('click', function () { select(id); });
                    node.addEventListener('keydown', function (event) {
                        if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); select(id); }
                    });
                    node.addEventListener('mouseenter', function () { if (!picked) { focus(id); } });
                    node.addEventListener('mouseleave', function () { if (!picked) { clear(); } });
                });

                // ── module legend ─────────────────────────────────────────────────────────────────────
                // Toggling a module HIDES its nodes and every edge touching them, because an edge to
                // something you cannot see is worse than no filter.
                var hidden = {};
                function applyModules() {
                    nodes.forEach(function (n) { n.classList.toggle('off', !!hidden[n.getAttribute('data-module')]); });
                    edges.forEach(function (e) {
                        var a = byId[e.getAttribute('data-from')], b = byId[e.getAttribute('data-to')];
                        e.classList.toggle('off', (a && a.classList.contains('off')) || (b && b.classList.contains('off')));
                    });
                }
                document.querySelectorAll('.mod').forEach(function (button) {
                    button.addEventListener('click', function () {
                        var module = button.getAttribute('data-module');
                        hidden[module] = !hidden[module];
                        button.setAttribute('aria-pressed', hidden[module] ? 'false' : 'true');
                        applyModules();
                    });
                });

                // ── find ──────────────────────────────────────────────────────────────────────────────
                var find = document.getElementById('graph-find');
                find.addEventListener('input', function () {
                    var needle = find.value.toLowerCase();
                    picked = null;
                    if (needle === '') { clear(); return; }
                    var first = null;
                    nodes.forEach(function (node) {
                        var hit = (node.getAttribute('data-search') || '').indexOf(needle) !== -1;
                        node.classList.toggle('lit', hit);
                        node.classList.toggle('dimmed', !hit);
                        if (hit && !first) { first = node; }
                    });
                    edges.forEach(function (e) { e.classList.add('dimmed'); e.classList.remove('lit'); });
                });

                document.getElementById('g-fit').addEventListener('click', fit);
                document.getElementById('g-reset').addEventListener('click', function () {
                    find.value = '';
                    hidden = {};
                    document.querySelectorAll('.mod').forEach(function (b) { b.setAttribute('aria-pressed', 'true'); });
                    applyModules();
                    picked = null;
                    clear();
                    fit();
                });

                // Deferred: the panel is laid out by CSS, so at parse time the canvas can still be 0x0 and
                // getBBox()/clientWidth would give fit() nothing to work with.
                requestAnimationFrame(function () { requestAnimationFrame(fit); });

                var resizeTimer = null;
                window.addEventListener('resize', function () {
                    clearTimeout(resizeTimer);
                    resizeTimer = setTimeout(fit, 120);
                });
            })();
        </script>
    @endpush
@endsection
