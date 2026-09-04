@extends('firefly-admin::layout')
@section('title', 'HTTP traffic')
@section('body')
    @php use Firefly\Admin\Format; @endphp

    <div class="head">
        <h1>HTTP traffic</h1>
        <p>The most recent requests this application served, newest first. Bodies and headers are never
           recorded — that is how these views leak credentials.</p>
    </div>

    <div class="panel">
        @include('firefly-admin::_panel-head', [
            'title' => 'Exchanges', 'count' => count($exchanges),
            'filter' => 'http-body', 'placeholder' => 'Filter by path, status or id…',
        ])
        @if ($exchanges === [])
            @include('firefly-admin::_empty', [
                'title' => 'No exchanges recorded',
                'body' => 'Recording is off, or nothing has been served since this process started. Under PHP-FPM the buffer must be cache-backed to survive a request — see <code>firefly.observability.httpexchanges</code>.',
            ])
        @else
            <div class="tw">
                <table>
                    <thead><tr><th>When</th><th>Method</th><th>Path</th><th>Status</th><th class="num">Took</th><th>Correlation</th></tr></thead>
                    <tbody id="http-body">
                    @foreach ($exchanges as $exchange)
                        <tr>
                            <td class="mono dim tight">{{ $exchange['timestamp'] > 0 ? Format::since($exchange['timestamp'], $now) : '—' }}</td>
                            <td class="tight"><span class="verb">{{ $exchange['method'] }}</span></td>
                            <td class="mono wrap">{{ $exchange['path'] }}</td>
                            <td class="tight"><span class="code {{ $exchange['status'] < 400 ? 'ok' : ($exchange['status'] < 500 ? 'warn' : 'err') }}">{{ $exchange['status'] ?: '—' }}</span></td>
                            <td class="num">{{ $exchange['duration'] }}</td>
                            <td class="mono dim tight">{{ $exchange['correlationId'] !== '' ? substr($exchange['correlationId'], 0, 8) : '—' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endsection
