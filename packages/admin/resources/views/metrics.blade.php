@extends('firefly-admin::layout')
@section('title', 'Metrics')
@section('body')
    @php use Firefly\Admin\Format; @endphp

    <div class="head">
        <h1>Metrics</h1>
        <p>Counters, timers and gauges recorded through the meter registry. Units are inferred from the
           meter name, the same convention the Prometheus exposition uses.</p>
    </div>

    <div class="panel">
        @include('firefly-admin::_panel-head', [
            'title' => 'Meters', 'count' => $slice->total, 'query' => $query, 'placeholder' => 'Search meters…',
        ])
        @if ($slice->isEmpty())
            @include('firefly-admin::_empty', $query->isFiltered()
                ? ['title' => 'Nothing matches', 'body' => 'No meter name or statistic contains that. <a href="'.e($query->link(['q' => null, 'page' => null])).'">Show them all</a>.']
                : ['title' => 'Nothing recorded yet', 'body' => 'The default registry keeps meters in process memory, so under PHP-FPM a page only ever sees its own request. Set <code>firefly.observability.metrics.store</code> to a cache store to accumulate across workers.'])
        @else
            <div class="tw">
                <table class="ftable">
                    @include('firefly-admin::_table-head', ['view' => $view, 'query' => $query])
                    <tbody>
                    @foreach ($slice->rows as $metric)
                        {{-- ONE ROW PER MEASUREMENT, ONE SLICE PER METER. The listing pages meters, so a
                             meter's statistics are drawn together however many there are, and only the
                             first of them names the meter — the blank cells under it are what says "these
                             readings are of one thing". --}}
                        @forelse ($metric['rows'] as $row)
                            <tr>
                                <td class="t-qual" title="{{ $metric['name'] }}">
                                    @if ($loop->first)
                                        <span class="nm">{{ Format::leafOf($metric['name'], '.') }}</span>
                                        <span class="ns stem">{{ Format::stemOf($metric['name'], '.') }}</span>
                                    @endif
                                </td>
                                <td class="t-token dim">{{ $row['statistic'] }}</td>
                                <td class="t-num">{{ $row['display'] }}</td>
                                <td class="t-meter">
                                    {{-- One shared scale across every meter, and across every PAGE of them:
                                         the bar answers "which of these is large", which is the only
                                         comparison a mixed-unit list supports, and an answer that changed
                                         when the reader turned the page would not be one. --}}
                                    <div class="bar"><i style="width:{{ $peak > 0 ? round(abs($row['value']) / $peak * 100, 2) : 0 }}%"></i></div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td class="t-qual" title="{{ $metric['name'] }}">
                                    <span class="nm">{{ Format::leafOf($metric['name'], '.') }}</span>
                                    <span class="ns stem">{{ Format::stemOf($metric['name'], '.') }}</span>
                                </td>
                                <td class="dim" colspan="3">no measurements</td>
                            </tr>
                        @endforelse
                    @endforeach
                    </tbody>
                </table>
            </div>
            @include('firefly-admin::_pager', ['slice' => $slice, 'query' => $query])
        @endif
    </div>
@endsection
