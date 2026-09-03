@extends('firefly-admin::layout')
@section('title', 'Overview')

@section('topchips')
    <span class="chip {{ $aggregate === 'UP' ? 'up' : ($aggregate === 'DOWN' ? 'down' : '') }}">{{ $aggregate }}</span>
    <span class="chip flat">{{ $bootMode }}</span>
@endsection

@section('body')
    @php
        use Firefly\Admin\Format;
        $down = array_values(array_filter($indicators, fn ($i) => $i['status'] !== 'UP'));
    @endphp

    <div class="head">
        <h1>Overview</h1>
        <p>Health, runtime and what this process wired at boot.</p>
    </div>

    <dl class="stats">
        <div class="stat">
            <dt>Health</dt>
            <dd><span class="chip {{ $aggregate === 'UP' ? 'up' : ($aggregate === 'DOWN' ? 'down' : '') }}">{{ $aggregate }}</span></dd>
        </div>
        <div class="stat"><dt>Indicators</dt><dd>{{ count($indicators) }}@if ($down !== [])<small>{{ count($down) }} down</small>@endif</dd></div>
        <div class="stat"><dt>Beans</dt><dd><a href="{{ $settings->url('beans') }}">{{ count($beans) }}</a></dd></div>
        <div class="stat"><dt>Routes</dt><dd><a href="{{ $settings->url('mappings') }}">{{ count($mappings) }}</a></dd></div>
        <div class="stat"><dt>Auto-config</dt><dd><a href="{{ $settings->url('conditions') }}">{{ count($positive) }}</a><small>{{ count($negative) }} off</small></dd></div>
        <div class="stat"><dt>Scheduled</dt><dd>{{ count($tasks) }}</dd></div>
        <div class="stat"><dt>Boot</dt><dd><span class="chip {{ $bootMode === 'compiled' ? 'up' : 'warn' }}">{{ $bootMode }}</span></dd></div>
    </dl>

    @if ($bootMode !== 'compiled')
        <p class="note">This process scanned its classes by reflection at startup — right while developing.
        Run <code>php artisan firefly:cache</code> before deploying for a zero-reflection boot.</p>
    @endif

    <div class="grid two" style="margin-top:16px">
        <div class="panel">
            @include('firefly-admin::_panel-head', ['title' => 'Health indicators', 'count' => count($indicators)])
            @if ($indicators === [])
                @include('firefly-admin::_empty', [
                    'title' => 'No indicators registered',
                    'body' => 'Implement <code>Firefly\Actuator\Health\HealthIndicator</code> and register it as a bean to see it here.',
                ])
            @else
                <div class="tw">
                    <table>
                        <tbody>
                        @foreach ($indicators as $indicator)
                            <tr>
                                <td class="mono tight">{{ $indicator['name'] }}</td>
                                <td class="tight"><span class="chip {{ $indicator['status'] === 'UP' ? 'up' : 'down' }}">{{ $indicator['status'] }}</span></td>
                                <td class="mono dim wrap">
                                    @if ($indicator['details'] === [])
                                        —
                                    @else
                                        {{ implode(' · ', array_map(
                                            fn ($k, $v) => $k.' '.Format::detail((string) $k, $v),
                                            array_keys($indicator['details']), $indicator['details']
                                        )) }}
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        <div class="panel">
            @include('firefly-admin::_panel-head', ['title' => 'Runtime', 'count' => count($info)])
            @if ($info === [])
                @include('firefly-admin::_empty', [
                    'title' => 'Nothing published',
                    'body' => 'No <code>InfoContributor</code> has contributed anything. Set <code>firefly.management.info.app</code>, or register your own contributor.',
                ])
            @else
                <div class="tw">
                    <table>
                        <tbody>
                        @foreach ($info as $key => $value)
                            <tr>
                                <td class="mono dim tight">{{ $key }}</td>
                                <td class="mono wrap">{{ $value }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    <div class="grid two" style="margin-top:16px">
        @if ($exchanges !== [])
            <div class="panel">
                @include('firefly-admin::_panel-head', ['title' => 'Recent requests', 'count' => count($exchanges)])
                <div class="tw">
                    <table>
                        <tbody>
                        @foreach ($exchanges as $exchange)
                            <tr>
                                <td class="tight"><span class="verb">{{ $exchange['method'] }}</span></td>
                                <td class="mono wrap">{{ $exchange['path'] }}</td>
                                <td class="tight">
                                    <span class="code {{ $exchange['status'] < 400 ? 'ok' : ($exchange['status'] < 500 ? 'warn' : 'err') }}">{{ $exchange['status'] }}</span>
                                </td>
                                <td class="num dim">{{ $exchange['duration'] }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                <div style="padding:9px 14px;border-top:1px solid var(--line)">
                    <a href="{{ $settings->url('http') }}">All traffic &rarr;</a>
                </div>
            </div>
        @endif

        @if ($metrics !== [])
            <div class="panel">
                @include('firefly-admin::_panel-head', ['title' => 'Metrics', 'count' => count($metrics)])
                <div class="tw">
                    <table>
                        <tbody>
                        @foreach (array_slice($metrics, 0, 8) as $metric)
                            <tr>
                                <td class="mono wrap">{{ $metric['name'] }}</td>
                                <td class="num">{{ $metric['rows'][0]['display'] ?? '—' }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                <div style="padding:9px 14px;border-top:1px solid var(--line)">
                    <a href="{{ $settings->url('metrics') }}">All metrics &rarr;</a>
                </div>
            </div>
        @endif
    </div>

    <div class="panel" style="margin-top:16px">
        @include('firefly-admin::_panel-head', ['title' => 'Registered endpoints', 'count' => count($endpoints)])
        <div style="padding:12px 14px;display:flex;flex-wrap:wrap;gap:6px">
            @foreach ($endpoints as $id)
                <span class="chip flat">{{ $id }}</span>
            @endforeach
        </div>
        <p class="note" style="padding:0 14px 12px;margin:0">Readable here in-process. Which of them answer
        over HTTP is a separate decision — see <code>firefly.management.endpoints.web.exposure.include</code>.</p>
    </div>
@endsection
