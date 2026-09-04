@extends('firefly-admin::layout')
@section('title', 'Datasource')
@section('body')
    <div class="head">
        <h1>Datasource</h1>
        <p>Where this application's data lives, how it holds the connection open, and what
           <code>#[Transactional]</code> compiled to. Connection settings come from Laravel's
           <code>config/database.php</code>; secrets are masked with the same rule the actuator's
           <code>env</code> endpoint uses.</p>
    </div>

    @if (! $available)
        <div class="panel">
            @include('firefly-admin::_empty', [
                'title' => 'No database manager is bound',
                'body' => 'This application never resolved <code>illuminate/database</code>, which is a legal
                           LaraFly application — the container, the web layer and the actuator do not need one.
                           Install it and configure a connection to see anything here.',
            ])
        </div>
    @else

    {{-- Whether the default connection actually answers is the first thing anyone wants, so it leads. --}}
    @isset($probe)
        <div class="panel">
            <header>
                <h2>Connectivity</h2>
                <span class="spacer"></span>
                <span class="chip {{ $probe['up'] ? 'up' : 'down' }}">{{ $probe['up'] ? 'UP' : 'DOWN' }}</span>
            </header>
            <dl class="stats">
                <div class="stat"><dt>Connection</dt><dd class="sm">{{ $probe['name'] }}</dd></div>
                <div class="stat"><dt>Server</dt><dd class="sm">{{ $probe['version'] !== '' ? $probe['version'] : '—' }}</dd></div>
            </dl>
            <p class="note">{{ $probe['detail'] }}</p>
        </div>
    @endisset

    <div class="panel">
        @include('firefly-admin::_panel-head', [
            'title' => 'Connections', 'count' => count($connections),
            'filter' => 'conn-body', 'placeholder' => 'Filter connections…',
        ])
        @if ($connections === [])
            @include('firefly-admin::_empty', [
                'title' => 'No connections configured',
                'body' => '<code>config/database.php</code> defines no connections at all.',
            ])
        @else
            <div class="tw">
                <table>
                    <thead><tr><th>Name</th><th>Driver</th><th>Target</th><th>Settings</th><th></th></tr></thead>
                    <tbody id="conn-body">
                    @foreach ($connections as $connection)
                        <tr>
                            <td class="mono tight">
                                {{ $connection['name'] }}
                                @if ($connection['default'])<span class="chip up">default</span>@endif
                            </td>
                            <td class="mono dim tight">{{ $connection['driver'] }}</td>
                            <td class="mono">{{ $connection['target'] }}</td>
                            <td>
                                @foreach ($connection['summary'] as $key => $value)
                                    <span class="pair"><b>{{ $key }}</b>{{ $value }}</span>
                                @endforeach
                                @foreach ($connection['options'] as $key => $value)
                                    <span class="pair opt"><b>{{ $key }}</b>{{ $value }}</span>
                                @endforeach
                            </td>
                            <td class="tight">
                                @if ($probeEnabled)
                                    <a class="act" href="?probe={{ urlencode($connection['name']) }}">Test</a>
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
        @include('firefly-admin::_panel-head', ['title' => 'Connection reuse', 'count' => count($pooling)])
        <div class="tw">
            <table>
                <thead><tr><th>Connection</th><th>Persistent</th><th>What that means</th></tr></thead>
                <tbody>
                @foreach ($pooling as $row)
                    <tr>
                        <td class="mono tight">{{ $row['name'] }}</td>
                        <td class="tight"><span class="chip {{ $row['persistent'] ? 'up' : '' }}">{{ $row['persistent'] ? 'on' : 'off' }}</span></td>
                        <td class="dim">{{ $row['note'] }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        {{-- Said plainly rather than dressed up as a pool gauge: inventing a number here would be worse
             than the absence it would be covering for. --}}
        <p class="note">
            PHP has no connection pool. What exists is PDO's <code>ATTR_PERSISTENT</code>, which keeps a
            connection open on the worker between requests — so under php-fpm the effective pool size is your
            worker count, decided by the process manager rather than by this framework. Under Octane the
            connection lives for the life of the worker either way. If you need real pooling in front of
            Postgres, that is pgbouncer's job, and it sits between this application and the server.
        </p>
    </div>

    {{-- THE WIZARD. A POST only: a GET must never be able to open an outbound socket to a host somebody
         put in a URL, which keeps this surface out of reach of a link, an image tag or a prefetch. --}}
    @if ($wizard->isAvailable())
        <details class="panel filters" @if ($trial !== null) open @endif>
            <summary>
                <span>Try a connection</span>
                <span class="spacer"></span>
                <span class="meta">nothing is written</span>
            </summary>

            @isset($trial)
                <p class="tip {{ $trial['ok'] ? '' : 'warnbox' }}" style="margin:14px 16px 0">
                    <strong>{{ $trial['ok'] ? 'Works' : 'Failed' }}.</strong>
                    {{ $trial['message'] }}
                    @if ($trial['version'] !== '') <span class="dim">· server {{ $trial['version'] }}</span>@endif
                </p>
                @if ($trial['snippet'] !== '')
                    <pre class="snippet">{{ $trial['snippet'] }}</pre>
                @endif
            @endisset

            <form method="post" action="{{ $settings->url('datasource') }}" class="filterform">
                @csrf
                <div class="frow">
                    <select name="driver" aria-label="Driver">
                        @foreach ($wizard->drivers() as $driver)
                            <option value="{{ $driver }}" @selected(($trialInput['driver'] ?? '') === $driver)>{{ $driver }}</option>
                        @endforeach
                    </select>
                    <input name="host" value="{{ $trialInput['host'] ?? '' }}" placeholder="host (127.0.0.1)" aria-label="Host">
                    <input name="port" value="{{ $trialInput['port'] ?? '' }}" placeholder="port" aria-label="Port" inputmode="numeric">
                </div>
                <div class="frow">
                    <input name="database" value="{{ $trialInput['database'] ?? '' }}" placeholder="database" aria-label="Database">
                    <input name="username" value="{{ $trialInput['username'] ?? '' }}" placeholder="username" aria-label="Username">
                    <input name="password" type="password" placeholder="password" aria-label="Password">
                </div>
                <div class="actions">
                    <button class="go" type="submit">Test connection</button>
                    <span class="hint">The settings are used for this request only — nothing is saved, and the
                        password never appears in the snippet.</span>
                </div>
            </form>
        </details>
    @elseif ($wizard->isProduction())
        <p class="note">
            The connection wizard is unavailable in production, and no configuration key changes that: a form
            that opens a socket to a host you type is a request-forgery tool, and its error messages
            distinguish “refused” from “timed out” well enough to map a private network.
        </p>
    @endif

    <div class="panel">
        @include('firefly-admin::_panel-head', [
            'title' => 'Transactional methods', 'count' => count($transactional),
            'filter' => 'tx-body', 'placeholder' => 'Filter methods…',
        ])
        @if ($transactional === [])
            @include('firefly-admin::_empty', [
                'title' => 'Nothing is proxied',
                'body' => 'No method carries <code>#[Transactional]</code>, or <code>firefly:cache</code> has
                           not run since one was added. The manifest is compiled, so a new annotation is
                           invisible until it is recompiled.',
            ])
        @else
            <div class="tw">
                <table>
                    <thead><tr><th>Class</th><th>Method</th><th>Propagation</th><th>Isolation</th><th>Read only</th><th>Timeout</th><th>Connection</th></tr></thead>
                    <tbody id="tx-body">
                    @foreach ($transactional as $row)
                        <tr>
                            <td class="mono cls">{{ $row['class'] }}</td>
                            <td class="mono tight">{{ $row['method'] }}()</td>
                            <td class="mono dim tight">{{ $row['propagation'] }}</td>
                            <td class="mono dim tight">{{ $row['isolation'] }}</td>
                            <td class="tight">@if ($row['readOnly'])<span class="chip">yes</span>@endif</td>
                            <td class="mono dim tight">{{ $row['timeout'] }}</td>
                            <td class="mono dim tight">{{ $row['connection'] }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    @endif
@endsection
