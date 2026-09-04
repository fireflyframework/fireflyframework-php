@extends('firefly-admin::layout')
@section('title', 'Scheduled')
@section('body')
    @php use Firefly\Admin\Format; @endphp

    <div class="head">
        <h1>Scheduled tasks</h1>
        <p>Methods registered by <code>#[Scheduled]</code>, with the cron expression or fixed interval that
           drives them.</p>
    </div>

    <div class="panel">
        @include('firefly-admin::_panel-head', ['title' => 'Tasks', 'count' => count($tasks)])
        @if ($tasks === [])
            @include('firefly-admin::_empty', [
                'title' => 'Nothing scheduled',
                'body' => 'Add <code>#[Scheduled]</code> to a bean method, then run the scheduler with <code>php artisan schedule:work</code>.',
            ])
        @else
            <div class="tw">
                <table>
                    <thead><tr><th>Runnable</th><th>Cron</th><th class="num">Fixed rate</th><th class="num">Fixed delay</th><th>Zone</th></tr></thead>
                    <tbody>
                    @foreach ($tasks as $task)
                        @php $runnable = is_string($task['runnable'] ?? null) ? $task['runnable'] : ''; @endphp
                        <tr>
                            <td class="cls"><span class="nm">{{ Format::shortClass($runnable) }}</span><span class="ns">{{ rtrim(Format::namespaceOf($runnable), '\\') }}</span></td>
                            <td class="mono dim">{{ $task['cron'] ?: '—' }}</td>
                            <td class="num dim">{{ $task['fixedRate'] ?: '—' }}</td>
                            <td class="num dim">{{ $task['fixedDelay'] ?: '—' }}</td>
                            <td class="mono dim">{{ $task['zone'] ?: '—' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endsection
