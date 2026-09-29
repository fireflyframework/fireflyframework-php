@extends('firefly-admin::layout')
@section('title', 'Bean explorer')
@section('body')
    @php use Firefly\Admin\BeanGraph; use Firefly\Admin\Format; @endphp
    <div class="head"><h1>Bean explorer</h1><p>Find a bean, follow its dependencies, and see what each module brings in.</p></div>
    <form method="get" action="{{ $settings->url('graph') }}" class="bx-search">
        <label for="bx-search">Find a bean</label>
        <input id="bx-search" data-filter="bean-search" name="q" value="{{ $explorer->get('q') }}" placeholder="Class, factory or stereotype" type="search">
        <button class="act" type="submit">Search</button>@if ($settings->allows('beans'))<a class="act" href="{{ $settings->url('beans') }}">Full catalogue</a>@endif
    </form>
    <p>{{ count($graph->nodes) }} beans · {{ count($graph->edges) }} relations · {{ count($modules->nodes) }} modules · {{ count($cycles) }} circular components · {{ count($graph->unresolved) }} unresolved types</p>

    @if ($explorer->get('bean') !== '' && $picked === null)
        <div class="panel"><h2>Bean not found</h2><p>The selected bean is not in this process's current catalogue. Search again or open the full catalogue.</p></div>
    @elseif ($picked !== null)
        @php $module = BeanGraph::moduleOf($picked['id']); @endphp
        <div class="panel bx-details">
            <h2>{{ $picked['label'] }}</h2><p class="mono wrap">{{ $picked['id'] }}</p>
            <p>{{ $picked['kind'] }} · {{ $picked['stereotype'] ?: 'No stereotype reported' }} · Scope: {{ $picked['scope'] ?: 'Not reported' }} · <a href="{{ $explorer->url(['module' => $module, 'bean' => null]) }}">{{ $module }}</a></p>
            @if ($picked['detail'])<p>Declared by: {{ $picked['detail'] }}</p>@endif
            @foreach ($conditions as $condition)<p>{{ $condition['outcome'] }} · {{ $condition['class'] }} · {{ $condition['condition'] }}</p>@endforeach
            @if ($settings->allows('beans'))<a href="{{ $settings->url('beans') }}?q={{ urlencode($picked['id']) }}">See it in the container →</a>@endif
        </div>
        @if ($focus !== null)
            <div class="panel">
                <div class="bx-controls"><a href="#bx-lists">Skip the drawing — go to the lists</a>
                    <span>Hops:</span>@foreach ([1,2,3,4] as $depth)<a class="act" @if($depth === $explorer->depth) aria-current="true" @endif href="{{ $explorer->url(['depth' => $depth]) }}">{{ $depth }}</a>@endforeach
                    @foreach (['both' => 'Both directions', 'in' => 'Dependents', 'out' => 'Dependencies'] as $dir => $label)<a class="act" @if($dir === $explorer->direction) aria-current="true" @endif href="{{ $explorer->url(['dir' => $dir]) }}">{{ $label }}</a>@endforeach
                </div>
                <h3 id="bx-caption" class="bx-caption">Around {{ $picked['label'] }} · {{ count($focus->positions) }} beans drawn</h3>
                @include('firefly-admin::_bx-drawing')
                <div class="bx-details">
                    <p>Arrows point to dependencies. Dashed arrows indicate factory production. All direct relations, including same-column and cyclic edges, appear in the lists.</p>
                    @foreach ($focus->overflow as $overflow)
                        <p><a href="{{ $explorer->url(['neighbor' => $overflow['source'], 'rel' => $overflow['direction'], 'in_page' => null, 'out_page' => null, $overflow['direction'].'_q' => null]) }}#bx-{{ $overflow['direction'] }}">+{{ $overflow['count'] }} more {{ $overflow['direction'] === 'in' ? 'dependents of' : 'dependencies of' }} {{ Format::shortClass($overflow['source']) }}</a></p>
                    @endforeach
                    @foreach ($focus->paths as $path)<p>Reached from an entry point: @foreach (array_slice($path, 0, $settings->graph->maxRows) as $id)<a href="{{ $explorer->bean($id) }}">{{ Format::shortClass($id) }}</a>@if (!$loop->last) → @endif @endforeach @if (count($path) > $settings->graph->maxRows) … <a href="#bx-paths">{{ count($path) - $settings->graph->maxRows }} further hops; complete chain below</a>@endif</p>@endforeach
                    @if ($focus->paths === [])<p>No entry-point chain shown. The bean may belong to a cycle, or path display is disabled.</p>@endif
                </div>
            </div>
        @endif
        <div id="bx-lists"><h2>All direct relations of {{ Format::shortClass($relationSource) }}</h2>
            @foreach ($relations as $side => $relation)
                <div class="panel" id="bx-{{ $side }}">
                    @include('firefly-admin::_panel-head', ['title' => $relation['title'], 'count' => $relation['slice']->total, 'query' => $relation['query'], 'placeholder' => 'Search these relations'])
                    <div class="tw"><table class="ftable"><caption class="sr-only">{{ $relation['title'] }} · {{ $relation['slice']->total }} relations{{ $relation['query']->search ? ' matching '.$relation['query']->search : '' }}</caption>
                        @include('firefly-admin::_table-head', ['view' => $relation['view'], 'query' => $relation['query']])
                        <tbody>@foreach ($relation['slice']->rows as $row)<tr><td class="t-qual" title="{{ $row['id'] }}"><a href="{{ $explorer->bean($row['id']) }}"><span class="nm">{{ Format::leafOf($row['id']) }}</span><span class="ns stem">{{ Format::stemOf($row['id']) }}</span></a></td><td class="t-qual" title="{{ $row['via'] }}"><span class="nm">{{ Format::leafOf($row['via']) ?: 'Direct' }}</span><span class="ns stem">{{ Format::stemOf($row['via']) }}</span></td><td class="t-token">{{ $row['type'] }}</td></tr>@endforeach</tbody>
                    </table></div>
                    @include('firefly-admin::_pager', ['slice' => $relation['slice'], 'query' => $relation['query']])
                </div>
            @endforeach
        </div>
    @elseif ($explorer->get('q') !== '' || $explorer->get('module') !== '')
        <div class="panel"><div class="bx-details"><h2>{{ $explorer->get('module') ?: 'Search results' }}</h2>
            @if ($explorer->get('module') !== '')<p>{{ count($exclusive) }} beans reachable only from this module. A bean with no dependents is marked unused outside.</p>@endif
        </div>
            @if ($explorer->get('module') !== '' && $modulePicked !== null && $moduleFocus !== null)
                <h3 id="bx-caption" class="bx-caption">Module neighbourhood around {{ $modulePicked['label'] }}</h3>
                @include('firefly-admin::_bx-drawing', ['graph' => $moduleGraph, 'nodes' => array_column($moduleGraph->nodes, null, 'id'), 'picked' => $modulePicked, 'focus' => $moduleFocus])
                <p class="bx-details">Foreign beans are grouped into module ports. This preview uses the focus budget; the catalogue below lists every bean in the selected module.</p>
            @endif
            @include('firefly-admin::_bx-beans')
        </div>
    @else
        <h2>Start here</h2><div class="grid two">
            @foreach (['Entry points' => $roots, 'Most depended on' => $starters] as $title => $entries)<div class="panel bx-details"><h3>{{ $title }}</h3><ul>@foreach ($entries as $node)<li><a href="{{ $explorer->bean($node['id']) }}">{{ $node['label'] }}</a> · {{ $node['in'] }} dependents · {{ $node['out'] }} dependencies</li>@endforeach</ul>@if ($entries === [])<p>No beans registered.</p>@endif</div>@endforeach
        </div>
    @endif

    @if ($picked === null && $explorer->get('q') === '')
        <div class="panel bx-details"><h2>Modules</h2>
            @if (count($modules->nodes) >= 3 && count($modules->nodes) <= $settings->graph->moduleMaxNodes)
                <div class="bx-module-map" role="group" aria-label="Modules and dependency counts">
                    @foreach ($modules->nodes as $module => $info)<a class="bx-module" href="{{ $explorer->url(['module' => $module, 'bean' => null, 'beans_page' => null]) }}" style="--hue:{{ $info['hue'] }}"><strong>{{ $info['sigil'] }} · {{ $module }}</strong><span>{{ $info['count'] }} beans</span>@foreach (array_slice(array_values(array_filter($modules->edges, fn ($edge) => $edge['from'] === $module)), 0, $settings->graph->maxRows) as $edge)@if ($edge['from'] === $module)<span>→ {{ $edge['to'] }} · {{ $edge['weight'] }}</span>@endif @endforeach<span>Open module for complete coupling</span></a>@endforeach
                </div>
            @else
                <p>{{ count($modules->nodes) < 3 ? 'Open a module to explore its beans.' : 'The module map exceeds its configured budget; every module remains available below.' }}</p>
            @endif
            <div class="tw"><table class="ftable">
                @include('firefly-admin::_table-head', ['view' => $moduleView, 'query' => $moduleQuery])
                <tbody>@foreach ($moduleSlice->rows as $row)<tr><td class="t-qual"><a href="{{ $explorer->url(['module' => $row['id'], 'bean' => null, 'beans_page' => null]) }}">{{ $row['sigil'] }} · {{ $row['id'] }}</a></td><td>{{ $row['count'] }}</td><td>{{ $row['cycle'] ?: '—' }}</td></tr>@endforeach</tbody>
            </table></div>
            @include('firefly-admin::_pager', ['slice' => $moduleSlice, 'query' => $moduleQuery])
            <h3>Module coupling</h3><p>Weight counts resolved dependency relations; Beans counts distinct targets; Via counts distinct interfaces; Concrete counts direct relations.</p>
            <a class="act" href="{{ $explorer->url(['produces' => $explorer->get('produces') === '1' ? null : '1']) }}">{{ $explorer->get('produces') === '1' ? 'Exclude factory production' : 'Include factory production' }}</a>
            <div class="tw"><table class="ftable"><caption class="sr-only">Module coupling</caption>
                @include('firefly-admin::_table-head', ['view' => $couplingView, 'query' => $couplingQuery])
                <tbody>@foreach ($couplingSlice->rows as $edge)<tr><td class="t-qual"><a href="{{ $explorer->url(['module' => $edge['from']]) }}">{{ $edge['from'] }}</a></td><td class="t-qual"><a href="{{ $explorer->url(['module' => $edge['to']]) }}">{{ $edge['to'] }}</a></td><td>{{ $edge['weight'] }}</td><td>{{ $edge['beans'] }}</td><td>{{ $edge['via'] }}</td><td>{{ $edge['concrete'] }}</td></tr>@endforeach</tbody>
            </table></div>
            @include('firefly-admin::_pager', ['slice' => $couplingSlice, 'query' => $couplingQuery])
            <p>{{ count($modules->cycles) }} module cycle components; membership is shown in the module list.</p>
        </div>
    @endif
    @if ($pathSlice->total > 0)
        <div class="panel" id="bx-paths">
            @include('firefly-admin::_panel-head', ['title' => 'Complete entry-point chains', 'count' => $pathSlice->total, 'query' => $pathQuery, 'placeholder' => 'Search chain members'])
            <div class="tw"><table class="ftable">@include('firefly-admin::_table-head', ['view' => $pathView, 'query' => $pathQuery])<tbody>
                @foreach ($pathSlice->rows as $row)<tr><td>{{ $row['chain'] }}</td><td>{{ $row['hop'] }}</td><td class="t-qual"><a href="{{ $explorer->bean($row['id']) }}">{{ $row['id'] }}</a></td></tr>@endforeach
            </tbody></table></div>
            @include('firefly-admin::_pager', ['slice' => $pathSlice, 'query' => $pathQuery])
        </div>
    @endif
    @if ($cycles !== [])
        <div class="panel">
            @include('firefly-admin::_panel-head', ['title' => 'Circular dependencies · wiring graph components', 'count' => $cycleSlice->total, 'query' => $cycleQuery, 'placeholder' => 'Search cycle members'])
            <p class="bx-details">These are complete strongly connected components of the wiring graph, including factory production; membership does not necessarily imply a runtime constructor cycle.</p>
            <div class="tw"><table class="ftable">@include('firefly-admin::_table-head', ['view' => $cycleView, 'query' => $cycleQuery])<tbody>
                @foreach ($cycleSlice->rows as $row)<tr><td><a href="{{ $explorer->url(['cycle' => $row['component'], 'cycles_page' => null, 'cycles_q' => null]) }}">{{ $row['component'] + 1 }}</a></td><td class="t-qual"><a href="{{ $explorer->bean($row['id']) }}">{{ $row['id'] }}</a></td></tr>@endforeach
            </tbody></table></div>
            @include('firefly-admin::_pager', ['slice' => $cycleSlice, 'query' => $cycleQuery])
        </div>
    @endif
    @if ($graph->unresolved !== [])
        <div class="panel">
            @include('firefly-admin::_panel-head', ['title' => 'Unresolved types', 'count' => $unresolvedSlice->total, 'query' => $unresolvedQuery, 'placeholder' => 'Search unresolved types'])
            <p class="bx-details">Outside the catalogue or ambiguous: the published metadata cannot identify the binding or choose between competing candidates.</p>
            <div class="tw"><table class="ftable">@include('firefly-admin::_table-head', ['view' => $unresolvedView, 'query' => $unresolvedQuery])<tbody>
                @foreach ($unresolvedSlice->rows as $row)<tr><td class="t-qual" title="{{ $row['id'] }}">{{ $row['id'] }}</td></tr>@endforeach
            </tbody></table></div>
            @include('firefly-admin::_pager', ['slice' => $unresolvedSlice, 'query' => $unresolvedQuery])
        </div>
    @endif
@endsection
