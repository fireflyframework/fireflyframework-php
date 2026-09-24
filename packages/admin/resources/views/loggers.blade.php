@extends('firefly-admin::layout')
@section('title', 'Loggers')
@section('body')
    @php $levelNames = is_array($levels ?? null) ? $levels : []; @endphp

    <div class="head">
        <h1>Loggers</h1>
        <p>Log channels and the level each is configured with.</p>
    </div>

    <div class="panel">
        @include('firefly-admin::_panel-head', [
            'title' => 'Channels', 'count' => $slice->total, 'query' => $query, 'placeholder' => 'Search channels…',
        ])
        @if ($slice->isEmpty())
            @include('firefly-admin::_empty', $query->isFiltered()
                ? ['title' => 'Nothing matches', 'body' => 'No channel name or level contains that. <a href="'.e($query->link(['q' => null, 'page' => null])).'">Show them all</a>.']
                : ['title' => 'No channels configured', 'body' => 'Nothing is defined under <code>logging.channels</code>.'])
        @else
            <div class="tw">
                <table class="ftable">
                    @include('firefly-admin::_table-head', ['view' => $view, 'query' => $query])
                    <tbody>
                    @foreach ($slice->rows as $channel)
                        <tr>
                            <td class="t-token">{{ $channel['name'] }}</td>
                            <td class="t-pill"><span class="code {{ in_array($channel['level'], ['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'], true) ? 'err' : (in_array($channel['level'], ['WARNING', 'NOTICE'], true) ? 'warn' : 'ok') }}">{{ $channel['level'] }}</span></td>
                            <td class="t-actions">
                                <form method="post" action="{{ $settings->url('loggers') }}" class="inline">
                                    @csrf
                                    <input type="hidden" name="logger" value="{{ $channel['name'] }}">
                                    <select name="level" aria-label="Level for {{ $channel['name'] }}">
                                        @foreach ($levelNames as $option)
                                            <option value="{{ $option }}" @selected($option === $channel['level'])>{{ $option }}</option>
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
            @include('firefly-admin::_pager', ['slice' => $slice, 'query' => $query])
        @endif
    </div>

    {{--
        Honesty about what the control does. LoggersEndpoint::setLevel() reaches into the Monolog handlers of
        the CURRENT process, so under PHP-FPM the change lasts exactly as long as this request. Saying so is
        better than letting someone believe they have changed production logging.
    --}}
    <p class="note">Applying a level calls the same endpoint <code>POST /actuator/loggers/{name}</code> does,
    which mutates this PHP process only. Under PHP-FPM the next request is a different process and reverts to
    the configured level — change <code>logging.channels</code> for anything that must persist.</p>
@endsection
