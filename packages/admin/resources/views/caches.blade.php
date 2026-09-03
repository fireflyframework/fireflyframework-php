@extends('firefly-admin::layout')
@section('title', 'Caches')
@section('body')
    <div class="head">
        <h1>Caches</h1>
        <p>The cache stores this application has configured. Several framework features read one:
           <code>firefly.observability.metrics.store</code>, resilience state, and scheduling locks.</p>
    </div>

    <div class="panel">
        @include('firefly-admin::_panel-head', [
            'title' => 'Stores', 'count' => count($stores),
            'filter' => 'caches-body', 'placeholder' => 'Filter stores…',
        ])
        @if ($stores === [])
            @include('firefly-admin::_empty', [
                'title' => 'No stores configured',
                'body' => 'Laravel ships a <code>config/cache.php</code> defining several stores. If this is empty, that file is missing.',
            ])
        @else
            <div class="tw">
                <table>
                    <thead><tr><th>Store</th><th>Driver</th><th>Default</th></tr></thead>
                    <tbody id="caches-body">
                    @foreach ($stores as $name => $store)
                        @php
                            $store = is_array($store) ? $store : [];
                            $label = is_string($store['name'] ?? null) ? $store['name'] : (string) $name;
                            $driver = is_string($store['driver'] ?? null) ? $store['driver'] : '';
                            $isDefault = ($store['default'] ?? false) === true;
                        @endphp
                        <tr>
                            <td class="mono tight">{{ $label }}</td>
                            <td class="mono dim">{{ $driver ?: '—' }}</td>
                            <td class="tight">@if ($isDefault)<span class="chip up">default</span>@endif</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <p class="note">
        @if ($defaultStore !== null)
            <code>cache.default</code> is <code>{{ $defaultStore }}</code>.
        @endif
        This view is read-only. Evicting a cache from a dashboard is destructive and needs an authorization
        story this package does not have — use <code>php artisan cache:clear</code>.
    </p>
@endsection
