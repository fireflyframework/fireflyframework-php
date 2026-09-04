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
            'title' => 'Mappings', 'count' => count($mappings),
            'filter' => 'map-body', 'placeholder' => 'Filter by path or handler…',
        ])
        @if ($mappings === [])
            @include('firefly-admin::_empty', [
                'title' => 'No routes mapped',
                'body' => 'Create one with <code>php artisan make:firefly-controller</code>, then re-run <code>firefly:cache</code> if this application boots compiled.',
            ])
        @else
            <div class="tw">
                <table>
                    <thead><tr><th>Method</th><th>Path</th><th>Handler</th><th>Name</th></tr></thead>
                    <tbody id="map-body">
                    @foreach ($mappings as $route)
                        @php $handler = is_string($route['handler'] ?? null) ? $route['handler'] : ''; @endphp
                        <tr>
                            <td class="tight"><span class="verb">{{ $route['httpMethod'] ?? '' }}</span></td>
                            <td class="mono wrap">{{ $route['path'] ?? '' }}</td>
                            <td class="cls"><span class="nm">{{ Format::shortClass($handler) }}</span><span class="ns">{{ rtrim(Format::namespaceOf($handler), '\\') }}</span></td>
                            <td class="mono dim tight">{{ $route['name'] ?: '—' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endsection
