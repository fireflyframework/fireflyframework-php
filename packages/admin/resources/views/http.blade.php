@extends('firefly-admin::layout')
@section('title', 'HTTP traffic')
@section('body')
    @php use Firefly\Admin\Format; @endphp

    <div class="head">
        <h1>HTTP traffic</h1>
        <p>The most recent requests this application served, newest first, with the correlation id and — when
           tracing is on — the trace id each one ran under. Bodies and headers are never recorded — that is how
           these views leak credentials.</p>
    </div>

    <div class="panel">
        @include('firefly-admin::_panel-head', [
            'title' => 'Exchanges', 'count' => $slice->total, 'query' => $query,
            'placeholder' => 'Search by path, status or id…',
        ])
        @if ($slice->isEmpty())
            @include('firefly-admin::_empty', $query->isFiltered()
                ? ['title' => 'Nothing matches', 'body' => 'No exchange\'s path, method, status or id contains that. <a href="'.e($query->link(['q' => null, 'page' => null])).'">Show them all</a>.']
                : ['title' => 'No exchanges recorded', 'body' => 'Recording is off, or nothing has been served since this process started. Under PHP-FPM the buffer must be cache-backed to survive a request — see <code>firefly.observability.httpexchanges</code>.'])
        @else
            <div class="tw">
                <table class="ftable">
                    @include('firefly-admin::_table-head', ['view' => $view, 'query' => $query])
                    {{-- The id stays on the <tbody> although the JavaScript row filter that named it is
                         gone: tests/Browser/ObservabilityTest.php reaches into `#http-body` to read the
                         Trace column's `title` back out of the DOM, which is the one assertion in this
                         wave's reach that can prove a trace id survived the whole round trip. --}}
                    <tbody id="http-body">
                    @foreach ($slice->rows as $exchange)
                        <tr>
                            <td class="t-stamp">{{ $exchange['timestamp'] > 0 ? Format::since($exchange['timestamp'], $now) : '—' }}</td>
                            <td class="t-pill"><span class="verb">{{ $exchange['method'] }}</span></td>
                            <td class="t-path" title="{{ $exchange['path'] }}">{{ $exchange['path'] }}</td>
                            <td class="t-pill"><span class="code {{ $exchange['status'] < 400 ? 'ok' : ($exchange['status'] < 500 ? 'warn' : 'err') }}">{{ $exchange['status'] ?: '—' }}</span></td>
                            <td class="t-num">{{ $exchange['duration'] }}</td>
                            <td class="t-token dim" title="{{ $exchange['correlationId'] }}">{{ $exchange['correlationId'] !== '' ? substr($exchange['correlationId'], 0, 8) : '—' }}</td>
                            <td class="t-token dim" title="{{ $exchange['traceId'] }}">{{ $exchange['traceId'] !== '' ? substr($exchange['traceId'], 0, 8) : '—' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @include('firefly-admin::_pager', ['slice' => $slice, 'query' => $query])
        @endif
    </div>
@endsection
