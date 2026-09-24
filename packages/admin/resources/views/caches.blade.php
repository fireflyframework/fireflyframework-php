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
            'title' => 'Stores', 'count' => $slice->total, 'query' => $query, 'placeholder' => 'Search stores…',
        ])
        @if ($slice->isEmpty())
            @include('firefly-admin::_empty', $query->isFiltered()
                ? ['title' => 'Nothing matches', 'body' => 'No store name or driver contains that. <a href="'.e($query->link(['q' => null, 'page' => null])).'">Show them all</a>.']
                : ['title' => 'No stores configured', 'body' => 'Laravel ships a <code>config/cache.php</code> defining several stores. If this is empty, that file is missing.'])
        @else
            <div class="tw">
                <table class="ftable">
                    @include('firefly-admin::_table-head', ['view' => $view, 'query' => $query])
                    <tbody>
                    @foreach ($slice->rows as $store)
                        <tr>
                            <td class="t-token">{{ $store['name'] }}</td>
                            <td class="t-token dim">{{ $store['driver'] ?: '—' }}</td>
                            <td class="t-pill">@if ($store['default'] !== '')<span class="chip up">default</span>@endif</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @include('firefly-admin::_pager', ['slice' => $slice, 'query' => $query])
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
