@extends('firefly-admin::layout')
@section('title', 'Feature flags')
@section('body')
    @php
        $sources = is_array($overview['sources'] ?? null) ? $overview['sources'] : [];
        $provider = is_array($overview['provider'] ?? null) ? $overview['provider'] : [];
    @endphp

    <div class="head">
        <h1>Feature flags</h1>
        <p>Inspect each flag, the layer that defines it, and its change history. Changes written to the flag store
           become visible to other processes on their next refresh.</p>
    </div>

    @if (session('data-message'))
        <p class="tip" role="status">{{ session('data-message') }}</p>
    @endif

    @if ($unavailable ?? false)
        <p class="tip warnbox" role="status">Feature flag data is unavailable. Retry after the flags endpoint recovers.</p>
    @elseif (! ($overview['writable'] ?? false))
        <p class="tip">Read-only: no flag store is configured. Enable <code>firefly.feature-flags.sources.store</code> to change flags at runtime.</p>
    @elseif (! ($overview['writesEnabled'] ?? false))
        <p class="tip">Read-only: enable <code>firefly.feature-flags.management.writes</code> to change flags.</p>
    @endif

    @if ($unavailable ?? false)
    @elseif (isset($rawDetail))
        <div class="panel">
            @include('firefly-admin::_panel-head', ['title' => 'Complete flag detail JSON', 'count' => ''])
            <p class="note">These JSON member names require the complete response to preserve their shape. Use the CLI or actuator to edit this definition.</p>
            <pre>{{ $rawDetail }}</pre>
        </div>
    @elseif (isset($missing))
        @include('firefly-admin::_empty', ['title' => 'No such flag', 'body' => 'No layer defines <code>'.e($missing).'</code>. <a href="'.e($settings->url('flags')).'">Back to every flag</a>.'])
    @elseif ($detail === null)
        <div class="panel">
            @include('firefly-admin::_panel-head', ['title' => 'Flags', 'count' => count($flags), 'filter' => 'flags-body', 'placeholder' => 'Filter flags…'])
            @if ($flags === [])
                @include('firefly-admin::_empty', ['title' => 'No flags defined', 'body' => 'Define a flag in configuration, a flag file, or the store.'])
            @else
                <div class="tw"><table>
                    <thead><tr><th>Key</th><th>State</th><th>Type</th><th>Default</th><th>From</th><th>Version</th><th></th></tr></thead>
                    <tbody id="flags-body">
                    @foreach ($flags as $flag)
                        <tr>
                            <td><a class="mono" href="{{ $settings->url('flags') }}?flag={{ rawurlencode((string) ($flag['key'] ?? '')) }}">{{ $flag['key'] ?? '' }}</a>
                                @if ($flag['expired'] ?? false)<span class="chip warn">expired</span>@endif
                            </td>
                            <td class="tight"><span class="chip {{ ($flag['state'] ?? '') === 'ENABLED' ? 'up' : 'down' }}">{{ $flag['state'] ?? '' }}</span></td>
                            <td class="tight dim">{{ $flag['type'] ?? '' }}{{ ($flag['targeting'] ?? false) ? ' · targeted' : '' }}</td>
                            <td class="tight mono">{{ $flag['defaultVariant'] ?? '—' }}</td>
                            <td class="tight"><span class="chip flat">{{ $flag['origin'] ?? '' }}</span></td>
                            <td class="tight dim">{{ $flag['version'] ?? '—' }}</td>
                            <td class="tight">
                                @if ($writable)
                                    @php $enabled = ($flag['state'] ?? '') === 'ENABLED'; @endphp
                                    <form method="post" action="{{ $settings->url('flags') }}">
                                        @csrf
                                        <input type="hidden" name="flag" value="{{ $flag['key'] ?? '' }}">
                                        <input type="hidden" name="op" value="{{ $enabled ? 'disable' : 'enable' }}">
                                        <input type="hidden" name="back" value="list">
                                        @if (($flag['version'] ?? null) !== null)
                                            <input type="hidden" name="expectedVersion" value="{{ $flag['version'] }}">
                                        @endif
                                        <button class="act" type="submit">{{ $enabled ? 'Disable' : 'Enable' }}</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table></div>
            @endif
        </div>

        <div class="panel">
            @include('firefly-admin::_panel-head', ['title' => 'Sources', 'count' => count($sources)])
            <div class="tw"><table>
                <thead><tr><th>Source</th><th>Status</th><th>Flags</th><th>Last refresh</th><th>Error</th></tr></thead>
                <tbody>
                @foreach ($sources as $source)
                    <tr>
                        <td class="mono">{{ $source['name'] ?? '' }}</td>
                        <td class="tight"><span class="chip {{ ($source['status'] ?? '') === 'UP' ? 'up' : (($source['status'] ?? '') === 'STALE' ? 'warn' : 'down') }}">{{ $source['status'] ?? '' }}</span></td>
                        <td class="tight">{{ $source['flags'] ?? 0 }}</td>
                        <td class="tight dim">{{ $source['lastRefresh'] ?? '—' }}</td>
                        <td class="dim">{{ $source['error'] ?? '' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
            <p class="note">Provider <code>{{ $provider['name'] ?? '' }}</code>, {{ $provider['status'] ?? '' }}.</p>
        </div>
    @else
        @php
            $key = (string) ($detail['key'] ?? '');
            $definition = is_array($detail['definition'] ?? null) ? $detail['definition'] : [];
            $history = is_array($detail['history'] ?? null) ? $detail['history'] : [];
            $version = $detail['version'] ?? null;
        @endphp

        <p><a href="{{ $settings->url('flags') }}">← Every flag</a></p>
        <div class="panel">
            @include('firefly-admin::_panel-head', ['title' => $key, 'count' => ($definition['state'] ?? '').' · from '.($detail['origin'] ?? '')])
            @if ($detail['expired'] ?? false)
                <p class="tip warnbox"><strong>Expired.</strong> Its <code>expires</code> date has passed. Remove the flag or update the date.</p>
            @endif
            @if ($writable)
                <form method="post" action="{{ $settings->url('flags') }}" class="inline">
                    @csrf
                    <input type="hidden" name="flag" value="{{ $key }}">
                    <input type="hidden" name="op" value="{{ ($definition['state'] ?? '') === 'ENABLED' ? 'disable' : 'enable' }}">
                    @if ($version !== null)<input type="hidden" name="expectedVersion" value="{{ $version }}">@endif
                    <button class="act" type="submit">{{ ($definition['state'] ?? '') === 'ENABLED' ? 'Disable' : 'Enable' }}</button>
                </form>
                <form method="post" action="{{ $settings->url('flags') }}" class="inline">
                    @csrf
                    <input type="hidden" name="flag" value="{{ $key }}">
                    <input type="hidden" name="op" value="default-variant">
                    @if ($version !== null)<input type="hidden" name="expectedVersion" value="{{ $version }}">@endif
                    <select name="variant" aria-label="Default variant of {{ $key }}">
                        @foreach ($variants as $variant)
                            <option value="{{ $variant }}" @selected($variant === ($definition['defaultVariant'] ?? null))>{{ $variant }}</option>
                        @endforeach
                    </select>
                    <button class="act" type="submit">Set default</button>
                </form>
                @if ($version !== null)
                    <form method="post" action="{{ $settings->url('flags') }}" class="inline">
                        @csrf
                        <input type="hidden" name="flag" value="{{ $key }}">
                        <input type="hidden" name="op" value="delete">
                        <input type="hidden" name="expectedVersion" value="{{ $version }}">
                        <button class="act danger" type="submit">Delete stored override</button>
                    </form>
                @endif
            @endif

            <form method="post" action="{{ $settings->url('flags') }}" style="margin-top:12px">
                @csrf
                <input type="hidden" name="flag" value="{{ $key }}">
                <input type="hidden" name="op" value="put">
                @if ($version !== null)<input type="hidden" name="expectedVersion" value="{{ $version }}">@endif
                <label for="flag-definition" class="dim">Definition (flagd JSON)</label>
                <textarea id="flag-definition" name="definition" rows="14" spellcheck="false" @disabled(! $writable)
                          style="width:100%;font-family:var(--mono);font-size:12.5px">{{ $definitionJson }}</textarea>
                @if ($writable)<button class="act" type="submit">Save definition</button>@endif
            </form>
        </div>

        <div class="panel">
            @include('firefly-admin::_panel-head', ['title' => 'Evaluation preview', 'count' => ''])
            <form method="get" action="{{ $settings->url('flags') }}">
                <input type="hidden" name="flag" value="{{ $key }}">
                <input type="hidden" name="evaluate" value="1">
                <label for="flag-context" class="dim">Context (JSON object)</label>
                <textarea id="flag-context" name="context" rows="3" spellcheck="false" style="width:100%;font-family:var(--mono);font-size:12.5px">{{ $context }}</textarea>
                <label for="flag-targeting-key" class="dim">Targeting key</label>
                <input id="flag-targeting-key" name="targetingKey" value="{{ $targetingKey }}">
                <button class="act" type="submit">Evaluate</button>
            </form>
            @if ($evaluation !== null)
                @if ($evaluation['ok'])
                    <table><tbody>
                        <tr><th>{{ $evaluation['valueLabel'] }}</th><td class="mono" data-evaluation="value">{{ $evaluation['valueJson'] }}</td></tr>
                        <tr><th>Variant</th><td class="mono">{{ $evaluation['body']['variant'] ?? '—' }}</td></tr>
                        <tr><th>Reason</th><td class="mono" data-evaluation="reason">{{ $evaluation['body']['reason'] ?? '' }}</td></tr>
                        <tr><th>Error</th><td class="mono">{{ $evaluation['body']['errorCode'] ?? '—' }}</td></tr>
                    </tbody></table>
                @else
                    <p class="tip warnbox">{{ $evaluation['error'] }}</p>
                @endif
            @endif
            <p class="note">The preview uses only this context and the application's attributes. It records no metric or exposure event.</p>
        </div>

        <div class="panel">
            @include('firefly-admin::_panel-head', ['title' => 'Layers', 'count' => count($layerRows)])
            <div class="tw"><table>
                <thead><tr><th>Source</th><th>Definition</th></tr></thead>
                <tbody>
                @foreach ($layerRows as $layer)
                    <tr><td class="tight mono">{{ $layer['source'] }}</td><td class="mono dim">{{ $layer['definition'] }}</td></tr>
                @endforeach
                </tbody>
            </table></div>
        </div>

        <div class="panel">
            @include('firefly-admin::_panel-head', ['title' => 'History', 'count' => count($history)])
            @if ($history === [])
                @include('firefly-admin::_empty', ['title' => 'No changes recorded', 'body' => 'Only writes to the flag store are recorded; this flag has none.'])
            @else
                <div class="tw"><table>
                    <thead><tr><th>#</th><th>Action</th><th>Actor</th><th>When (UTC)</th></tr></thead>
                    <tbody id="flag-history">
                    @foreach ($history as $change)
                        <tr>
                            <td class="tight dim">{{ $change['id'] ?? '' }}</td>
                            <td class="tight mono">{{ $change['action'] ?? '' }}</td>
                            <td class="mono">{{ $change['actor'] ?? '—' }}</td>
                            <td class="tight dim">{{ $change['changedAt'] ?? '' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table></div>
            @endif
        </div>
    @endif
@endsection
