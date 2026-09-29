@php
    use Firefly\Admin\BeanGraph;
    $cycleMembers = [];
    foreach ($cycles as $group => $members) {
        foreach ($members as $member) { $cycleMembers[$member] = $group; }
    }
@endphp
                <div class="bx" tabindex="0" aria-label="Scrollable bean neighbourhood">
                    <div class="bx-heads" style="width:{{ $focus->width }}px;grid-template-columns:repeat({{ count($focus->columns) }},176px);gap:28px">
                        @foreach ($focus->columns as $column)<span>{{ $column === 0 ? 'This bean' : ($column < 0 ? 'Dependents · '.abs($column).' hop'.(abs($column)>1?'s':'') : 'Dependencies · '.$column.' hop'.($column>1?'s':'')) }}</span>@endforeach
                    </div>
                    <div class="bx-drawing" role="group" aria-labelledby="bx-caption" style="width:{{ $focus->width }}px;height:{{ $focus->height }}px">
                        <svg class="bx-edges" width="{{ $focus->width }}" height="{{ $focus->height }}" aria-hidden="true">
                            <defs><marker id="bx-arrow" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="5" markerHeight="5" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z" fill="currentColor"/></marker></defs>
                            @foreach (array_filter($graph->edges, fn ($edge) => isset($focus->positions[$edge['from']], $focus->positions[$edge['to']])) as $index => $edge)
                                    @php
                                        $a = $focus->positions[$edge['from']]; $b = $focus->positions[$edge['to']];
                                        $x1 = $a['x'] + 176; $x2 = $b['x']; $y1 = $a['y'] + 19; $y2 = $b['y'] + 19;
                                        $fan = (($index % 5) - 2) * 3;
                                        $c1 = ($x1 + $x2) / 2 + $fan; $c2 = $c1;
                                        $cyclic = isset($cycleMembers[$edge['from']], $cycleMembers[$edge['to']]) && $cycleMembers[$edge['from']] === $cycleMembers[$edge['to']];
                                        if ($a['column'] === $b['column']) { $x2 = $b['x'] + 176; $c1 = $c2 = $x1 + 12 + abs($fan); }
                                    @endphp
                                    <path class="bx-edge {{ $edge['type'] }}{{ $cyclic ? ' cycle' : '' }}" d="M{{ $x1 }},{{ $y1 }} C{{ $c1 }},{{ $edge['from'] === $edge['to'] ? $y1 - 24 : $y1 }} {{ $c2 }},{{ $edge['from'] === $edge['to'] ? $y2 + 24 : $y2 }} {{ $x2 }},{{ $y2 }}" marker-end="url(#bx-arrow)"/>
                            @endforeach
                        </svg>
                        @foreach ($focus->positions as $id => $position)
                            @php $node = $nodes[$id]; $mod = $node['kind'] === 'module' ? substr($id, 7) : BeanGraph::moduleOf($id); $inCycle = isset($cycleMembers[$id]); @endphp
                            <a class="bx-node{{ $id === $picked['id'] ? ' is-focus' : '' }}" href="{{ $node['kind'] === 'module' ? $explorer->url(['module' => substr($id, 7), 'bean' => null]) : $explorer->bean($id) }}" data-id="{{ $id }}" data-column="{{ $position['column'] }}" style="left:{{ $position['x'] }}px;top:{{ $position['y'] }}px;--hue:{{ $modules->nodes[$mod]['hue'] }}" @if ($id === $picked['id']) aria-current="true" @endif aria-label="{{ $node['label'] }}, {{ $node['kind'] }} in {{ $mod }}; {{ $node['in'] }} dependents, {{ $node['out'] }} dependencies{{ $inCycle ? '; in a wiring graph cycle' : '' }}" title="{{ $id }} · {{ $mod }}">
                                <span class="bx-name">{{ $node['label'] }}</span><span class="bx-sigil">{{ $modules->nodes[$mod]['sigil'] }}</span>
                                <span class="bx-sub">{{ $node['kind'] }} · {{ $node['in'] }} ↑ {{ $node['out'] }} ↓{{ $inCycle ? ' · cycle' : '' }}</span>
                            </a>
                        @endforeach
                    </div>
                </div>
