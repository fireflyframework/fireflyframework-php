@extends('firefly-admin::layout')
@section('title', 'Routes')
@section('body')
    @php use Firefly\Admin\Format; @endphp

    <div class="head">
        <h1>Routes</h1>
        <p>The compiled route table the dispatcher serves from, discovered from your
           <code>#[RestController]</code> and <code>#[Controller]</code> classes.</p>
    </div>

    <div class="panel">
        @include('firefly-admin::_panel-head', [
            'title' => 'Mappings', 'count' => $slice->total, 'query' => $query,
            'placeholder' => 'Search by path or handler…',
        ])
        @if ($slice->isEmpty())
            @include('firefly-admin::_empty', $query->isFiltered()
                ? ['title' => 'Nothing matches', 'body' => 'No route\'s path, handler or name contains that. <a href="'.e($query->link(['q' => null, 'page' => null])).'">Show them all</a>.']
                : ['title' => 'No routes mapped', 'body' => 'Create one with <code>php artisan make:firefly-controller</code>, then re-run <code>firefly:cache</code> if this application boots compiled.'])
        @else
            <div class="tw">
                <table class="ftable">
                    @include('firefly-admin::_table-head', ['view' => $view, 'query' => $query])
                    <tbody>
                    @foreach ($slice->rows as $route)
                        <tr>
                            <td class="t-pill"><span class="verb">{{ $route['httpMethod'] }}</span></td>
                            <td class="t-path" title="{{ $route['path'] }}">
                                @if ($settings->routeDetail)
                                    <a class="route-link" data-route="{{ $route['httpMethod'] }} {{ $route['path'] }}" href="{{ $query->link().(str_contains($query->link(), '?') ? '&' : '?').'route='.rawurlencode($route['httpMethod'].' '.$route['path']) }}#route-detail"><span class="sr">Inspect {{ $route['httpMethod'] }} </span>{{ $route['path'] }}</a>
                                @else
                                    {{ $route['path'] }}
                                @endif
                                @if ($route['shadowed'])<span class="code warn">Shadowed</span>@endif
                            </td>
                            <td class="t-qual" title="{{ $route['handler'] }}">
                                <span class="nm">{{ Format::leafOf($route['handler']) }}</span>
                                <span class="ns stem">{{ Format::stemOf($route['handler']) }}</span>
                            </td>
                            <td class="t-token" title="{{ $route['name'] }}">{{ $route['name'] ?: '—' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @include('firefly-admin::_pager', ['slice' => $slice, 'query' => $query])
        @endif
    </div>
@endsection
