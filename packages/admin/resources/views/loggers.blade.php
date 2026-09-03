@extends('firefly-admin::layout')
@section('title', 'Loggers')
@section('body')
    @php
        $levelNames = is_array($levels ?? null) ? $levels : [];
        $channels = is_array($loggers ?? null) ? $loggers : [];
    @endphp

    <div class="head">
        <h1>Loggers</h1>
        <p>Log channels and the level each is configured with.</p>
    </div>

    <div class="panel">
        <h2>Channels <span>{{ count($channels) }}</span></h2>
        @if ($channels === [])
            <p class="empty">No channels configured under <code>logging.channels</code>.</p>
        @else
            <div class="tw">
                <table>
                    <thead><tr><th>Channel</th><th>Configured level</th><th style="width:1%">Set</th></tr></thead>
                    <tbody>
                    @foreach ($channels as $name => $logger)
                        @php $level = is_array($logger) && is_string($logger['configuredLevel'] ?? null) ? $logger['configuredLevel'] : 'INFO'; @endphp
                        <tr>
                            <td class="mono">{{ $name }}</td>
                            <td class="mono muted">{{ $level }}</td>
                            <td>
                                <form method="post" action="{{ $settings->url('loggers') }}" style="display:flex;gap:6px">
                                    @csrf
                                    <input type="hidden" name="logger" value="{{ $name }}">
                                    <select name="level" aria-label="Level for {{ $name }}">
                                        @foreach ($levelNames as $option)
                                            <option value="{{ $option }}" @selected($option === $level)>{{ $option }}</option>
                                        @endforeach
                                    </select>
                                    <button class="act" type="submit">Apply</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{--
        Honesty about what this control does. LoggersEndpoint::setLevel() reaches into the Monolog handlers of
        the CURRENT process, so under PHP-FPM the change lasts exactly as long as this request. Saying so is
        better than letting someone believe they have changed production logging.
    --}}
    <p class="note">Applying a level calls the same endpoint <code>POST /actuator/loggers/{name}</code> does,
    which mutates this PHP process only. Under PHP-FPM the next request is a different process and reverts to
    the configured level — change <code>logging.channels</code> for anything that must persist.</p>
@endsection
