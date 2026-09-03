@extends('firefly-admin::layout')
@section('title', 'Metrics')
@section('body')
    <div class="head">
        <h1>Metrics</h1>
        <p>Counters, timers and gauges recorded through the meter registry.</p>
    </div>

    <div class="panel">
        <h2>Meters <span>{{ count($metrics) }}</span></h2>
        @if ($metrics === [])
            <p class="empty">Nothing recorded yet. Note that the default registry keeps meters in process
            memory, so under PHP-FPM a scrape only ever sees its own request — set
            <code>firefly.observability.metrics.store</code> to a cache store to accumulate across workers.</p>
        @else
            @include('firefly-admin::_filter', ['target' => 'metrics-body', 'placeholder' => 'Filter by meter name…'])
            <div class="tw">
                <table>
                    <thead><tr><th>Meter</th><th>Statistic</th><th class="num">Value</th></tr></thead>
                    <tbody id="metrics-body">
                    @foreach ($metrics as $metric)
                        @php $measurements = is_array($metric['measurements']) ? $metric['measurements'] : []; @endphp
                        @forelse ($measurements as $measurement)
                            <tr>
                                <td class="mono wrapish">{{ $loop->first ? $metric['name'] : '' }}</td>
                                <td class="mono muted">{{ $measurement['statistic'] ?? '' }}</td>
                                <td class="num">{{ is_numeric($measurement['value'] ?? null) ? rtrim(rtrim(number_format((float) $measurement['value'], 4, '.', ''), '0'), '.') : '—' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td class="mono wrapish">{{ $metric['name'] }}</td>
                                <td class="muted" colspan="2">no measurements</td>
                            </tr>
                        @endforelse
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endsection
