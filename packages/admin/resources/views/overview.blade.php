@extends('firefly-admin::layout')
@section('title', 'Overview')
@section('body')
    @php
        $status = is_string($health['status'] ?? null) ? $health['status'] : 'UNKNOWN';
        $components = is_array($health['components'] ?? null) ? $health['components'] : [];
        $positive = is_array($conditions['positiveMatches'] ?? null) ? $conditions['positiveMatches'] : [];
        $negative = is_array($conditions['negativeMatches'] ?? null) ? $conditions['negativeMatches'] : [];
    @endphp

    <div class="head">
        <h1>Overview</h1>
        <p>What this process wired at boot, and how it is doing now.</p>
    </div>

    <dl class="cards">
        <div>
            <dt>Health</dt>
            <dd><span class="pill {{ $status === 'UP' ? 'up' : 'down' }}">{{ $status }}</span></dd>
        </div>
        <div><dt>Beans</dt><dd>{{ count($beans) }}</dd></div>
        <div><dt>Routes</dt><dd>{{ count($mappings) }}</dd></div>
        <div><dt>Auto-config met</dt><dd>{{ count($positive) }}</dd></div>
        <div><dt>Backed off</dt><dd>{{ count($negative) }}</dd></div>
        <div>
            <dt>Boot</dt>
            <dd><span class="pill {{ $bootMode === 'compiled' ? 'up' : 'off' }}">{{ $bootMode }}</span></dd>
        </div>
    </dl>

    @if ($bootMode !== 'compiled')
        <p class="note">This process scanned its classes by reflection at startup. That is right while
        developing; run <code>php artisan firefly:cache</code> before deploying.</p>
    @endif

    @if ($components === [])
        <div class="panel" style="margin-top:18px">
            <h2>Health indicators</h2>
            <p class="empty">The health endpoint is reporting its aggregate status only. Set
            <code>firefly.management.endpoint.health.show-details</code> to <code>always</code> to see each
            indicator and its details here.</p>
        </div>
    @else
        <div class="panel" style="margin-top:18px">
            <h2>Health indicators <span>{{ count($components) }}</span></h2>
            <div class="tw">
                <table>
                    <thead><tr><th>Indicator</th><th>Status</th><th>Details</th></tr></thead>
                    <tbody>
                    @foreach ($components as $name => $component)
                        @php $s = is_array($component) && is_string($component['status'] ?? null) ? $component['status'] : 'UNKNOWN'; @endphp
                        <tr>
                            <td class="mono">{{ $name }}</td>
                            <td><span class="pill {{ $s === 'UP' ? 'up' : 'down' }}">{{ $s }}</span></td>
                            <td class="mono muted wrapish">
                                @php $d = is_array($component) && is_array($component['details'] ?? null) ? $component['details'] : []; @endphp
                                {{ $d === [] ? '—' : json_encode($d, JSON_UNESCAPED_SLASHES) }}
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <div class="panel">
        <h2>Build information <span>/actuator/info</span></h2>
        @if ($info === [])
            <p class="empty">No <code>InfoContributor</code> has published anything. Set
            <code>firefly.management.info.app</code>, or register your own contributor.</p>
        @else
            <div class="tw">
                <table>
                    <tbody>
                    @foreach ($info as $key => $value)
                        <tr>
                            <td class="mono" style="width:220px">{{ $key }}</td>
                            <td class="mono muted wrapish">{{ is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_SLASHES) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <div class="panel">
        <h2>Registered endpoints <span>{{ count($endpoints) }}</span></h2>
        <div style="padding:14px 16px;display:flex;flex-wrap:wrap;gap:6px">
            @foreach ($endpoints as $id)
                <span class="pill on">{{ $id }}</span>
            @endforeach
        </div>
        <p class="empty" style="padding-top:0">These are readable here in-process. Which of them answer over
        HTTP is a separate decision — see <code>firefly.management.endpoints.web.exposure.include</code>.</p>
    </div>
@endsection
