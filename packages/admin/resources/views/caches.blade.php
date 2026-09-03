@extends('firefly-admin::layout')
@section('title', 'Caches')
@section('body')
    @php
        $stores = is_array($cacheManagers ?? null) ? $cacheManagers : (is_array($stores ?? null) ? $stores : $caches);
        $rows = [];
        foreach (is_array($stores) ? $stores : [] as $name => $store) {
            $rows[] = [
                'name' => (string) $name,
                'driver' => is_array($store) && is_string($store['driver'] ?? null) ? $store['driver'] : '',
                'default' => is_array($store) && ($store['default'] ?? false) === true,
            ];
        }
    @endphp

    <div class="head">
        <h1>Caches</h1>
        <p>The cache stores this application has configured. Several framework features read one:
           <code>firefly.observability.metrics.store</code>, resilience state, and scheduling locks.</p>
    </div>

    <div class="panel">
        @include('firefly-admin::_panel-head', ['title' => 'Stores', 'count' => count($rows)])
        @if ($rows === [])
            @include('firefly-admin::_empty', [
                'title' => 'No stores configured',
                'body' => 'Laravel ships a <code>config/cache.php</code> with several stores defined. If this is empty, the config file is missing.',
            ])
        @else
            <div class="tw">
                <table>
                    <thead><tr><th>Store</th><th>Driver</th><th>Default</th></tr></thead>
                    <tbody>
                    @foreach ($rows as $row)
                        <tr>
                            <td class="mono tight">{{ $row['name'] }}</td>
                            <td class="mono dim">{{ $row['driver'] ?: '—' }}</td>
                            <td class="tight">@if ($row['default'])<span class="chip up">default</span>@endif</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <p class="note">This view is read-only. Evicting a cache from a dashboard is a destructive operation and
    needs an authorization story this package does not have — use <code>php artisan cache:clear</code>.</p>
@endsection
