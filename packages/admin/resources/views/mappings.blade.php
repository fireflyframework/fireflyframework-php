@extends('firefly-admin::layout')
@section('title', 'Mappings')
@section('body')
    <div class="head">
        <h1>Mappings</h1>
        <p>The compiled route table the dispatcher serves from — discovered from your
           <code>#[RestController]</code> and <code>#[Controller]</code> classes.</p>
    </div>

    <div class="panel">
        <h2>Routes <span>{{ count($mappings) }}</span></h2>
        @include('firefly-admin::_filter', ['target' => 'map-body', 'placeholder' => 'Filter by path or handler…'])
        @if ($mappings === [])
            <p class="empty">No routes mapped.</p>
        @else
            <div class="tw">
                <table>
                    <thead><tr><th>Method</th><th>Path</th><th>Handler</th><th>Name</th></tr></thead>
                    <tbody id="map-body">
                    @foreach ($mappings as $route)
                        <tr>
                            <td class="mono">{{ $route['httpMethod'] ?? '' }}</td>
                            <td class="mono wrapish">{{ $route['path'] ?? '' }}</td>
                            <td class="mono muted wrapish">{{ $route['handler'] ?? '' }}</td>
                            <td class="mono muted">{{ $route['name'] ?: '—' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endsection
