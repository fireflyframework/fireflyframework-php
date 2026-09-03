@extends('firefly-admin::layout')
@section('title', 'Metrics')
@section('body')
    @php
        $peak = 0.0;
        foreach ($metrics as $metric) { foreach ($metric['rows'] as $row) { $peak = max($peak, abs($row['value'])); } }
    @endphp

    <div class="head">
        <h1>Metrics</h1>
        <p>Counters, timers and gauges recorded through the meter registry. Units are inferred from the
           meter name, the same convention the Prometheus exposition uses.</p>
    </div>

    <div class="panel">
        @include('firefly-admin::_panel-head', [
            'title' => 'Meters', 'count' => count($metrics),
            'filter' => 'metrics-body', 'placeholder' => 'Filter meters…',
        ])
        @if ($metrics === [])
            @include('firefly-admin::_empty', [
                'title' => 'Nothing recorded yet',
                'body' => 'The default registry keeps meters in process memory, so under PHP-FPM a page only ever sees its own request. Set <code>firefly.observability.metrics.store</code> to a cache store to accumulate across workers.',
            ])
        @else
            <div class="tw">
                <table>
                    <thead><tr><th>Meter</th><th>Statistic</th><th class="num">Value</th><th style="width:22%">Relative</th></tr></thead>
                    <tbody id="metrics-body">
                    @foreach ($metrics as $metric)
                        @forelse ($metric['rows'] as $row)
                            <tr>
                                <td class="mono wrap">{{ $loop->first ? $metric['name'] : '' }}</td>
                                <td class="mono dim tight">{{ $row['statistic'] }}</td>
                                <td class="num">{{ $row['display'] }}</td>
                                <td>
                                    {{-- One shared scale across every meter: the bar answers "which of these is
                                         large", which is the only comparison a mixed-unit list supports. --}}
                                    <div class="bar"><i style="width:{{ $peak > 0 ? round(abs($row['value']) / $peak * 100, 2) : 0 }}%"></i></div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td class="mono wrap">{{ $metric['name'] }}</td>
                                <td class="dim" colspan="3">no measurements</td>
                            </tr>
                        @endforelse
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endsection
