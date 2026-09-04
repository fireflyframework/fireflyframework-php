@extends('firefly-admin::layout')
@section('title', 'Feature switches')
@section('body')
    <div class="head">
        <h1>Feature switches</h1>
        <p>The framework switches this application is running with, where each value came from, and — outside
           production — a control to change it. A change is written to one file and merged over configuration
           at boot; it is never written into <code>.env</code>.</p>
    </div>

    @if (session('data-message'))
        <p class="tip">{{ session('data-message') }}</p>
    @endif

    @if ($production)
        <p class="tip warnbox">
            <strong>Production.</strong> This console is read-only here, and no configuration key changes that.
            A dashboard that can alter a running application is a remote-control surface; one reachable in
            production is a vulnerability however carefully it is configured.
        </p>
    @elseif (! $writable)
        <p class="tip">
            Read-only. Set <code>firefly.admin.settings.writable</code> to get controls. It is a separate key
            from <code>enabled</code> on purpose: seeing what is switched on should never imply being able to
            switch it.
        </p>
    @endif

    @if ($overrides !== [])
        <div class="panel">
            @include('firefly-admin::_panel-head', ['title' => 'Active overrides', 'count' => count($overrides)])
            <div class="tw">
                <table>
                    <thead><tr><th>Key</th><th>Value</th></tr></thead>
                    <tbody>
                    @foreach ($overrides as $key => $value)
                        <tr>
                            <td class="mono">{{ $key }}</td>
                            <td class="tight"><span class="bool {{ $value ? 'yes' : 'no' }}">{{ $value ? 'on' : 'off' }}</span></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <p class="note">
                Written to <code>{{ $file }}</code>. Deleting that file restores your configured values
                exactly — nothing else on disk was changed.
                @if ($writable)
                    <form method="post" action="{{ $settings->url('settings') }}" style="margin-top:10px">
                        @csrf
                        <input type="hidden" name="op" value="reset">
                        <button class="act danger" type="submit">Clear every override</button>
                    </form>
                @endif
            </p>
        </div>
    @endif

    @foreach ($toggleGroups as $group)
        @php $rows = array_values(array_filter($toggles, fn ($t) => $t['toggle']->group === $group)); @endphp
        @continue ($rows === [])
        <div class="panel">
            @include('firefly-admin::_panel-head', ['title' => $group, 'count' => count($rows)])
            <div class="tw">
                <table>
                    <thead><tr><th>Switch</th><th>Key</th><th>State</th><th>From</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($rows as $row)
                        <tr>
                            <td>
                                <strong>{{ $row['toggle']->label }}</strong>
                                <div class="dim" style="font-size:12px;max-width:64ch">{{ $row['toggle']->blurb }}</div>
                            </td>
                            <td class="mono dim">{{ $row['toggle']->key }}</td>
                            <td class="tight"><span class="bool {{ $row['value'] ? 'yes' : 'no' }}">{{ $row['value'] ? 'on' : 'off' }}</span></td>
                            <td class="tight">
                                <span class="chip flat">{{ $row['source'] }}</span>
                            </td>
                            <td class="tight">
                                @if ($writable)
                                    <form method="post" action="{{ $settings->url('settings') }}">
                                        @csrf
                                        <input type="hidden" name="key" value="{{ $row['toggle']->key }}">
                                        <input type="hidden" name="value" value="{{ $row['value'] ? '0' : '1' }}">
                                        <button class="act" type="submit">Turn {{ $row['value'] ? 'off' : 'on' }}</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endforeach

    <p class="note">
        <strong>from</strong> says where the effective value came from: <code>config</code> means your
        configuration set it, <code>default</code> means the framework's, and <code>console</code> means this
        page overrode it. Only a fixed list of framework switches appears here — the console cannot express a
        write to a key nobody put on that list, which is what keeps it a feature switch rather than a remote
        configuration endpoint.
    </p>
@endsection
