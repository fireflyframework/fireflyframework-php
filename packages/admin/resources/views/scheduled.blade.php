@extends('firefly-admin::layout')
@section('title', 'Scheduled')
@section('body')
    <div class="head">
        <h1>Scheduled tasks</h1>
        <p>Methods registered by <code>#[Scheduled]</code>, with the cron expression or fixed interval that
           drives them.</p>
    </div>

    <div class="panel">
        <h2>Tasks <span>{{ count($tasks) }}</span></h2>
        @if ($tasks === [])
            <p class="empty">Nothing scheduled. Add <code>#[Scheduled]</code> to a bean method.</p>
        @else
            <div class="tw">
                <table>
                    <thead><tr><th>Runnable</th><th>Cron</th><th class="num">Fixed rate</th><th class="num">Fixed delay</th><th>Zone</th></tr></thead>
                    <tbody>
                    @foreach ($tasks as $task)
                        <tr>
                            <td class="mono wrapish">{{ $task['runnable'] ?? '' }}</td>
                            <td class="mono muted">{{ $task['cron'] ?: '—' }}</td>
                            <td class="num muted">{{ $task['fixedRate'] ?: '—' }}</td>
                            <td class="num muted">{{ $task['fixedDelay'] ?: '—' }}</td>
                            <td class="mono muted">{{ $task['zone'] ?: '—' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endsection
