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
        @include('firefly-admin::_panel-head', [
            'title' => 'Tasks', 'count' => $slice->total, 'query' => $query,
            'placeholder' => 'Search by runnable, cron or zone…',
        ])
        @if ($slice->isEmpty())
            @include('firefly-admin::_empty', $query->isFiltered()
                ? ['title' => 'Nothing matches', 'body' => 'No task\'s runnable, cron expression or zone contains that. <a href="'.e($query->link(['q' => null, 'page' => null])).'">Show them all</a>.']
                : ['title' => 'Nothing scheduled', 'body' => 'Add <code>#[Scheduled]</code> to a bean method, then run the scheduler with <code>php artisan schedule:work</code>.'])
        @else
            <div class="tw">
                <table class="ftable">
                    @include('firefly-admin::_table-head', ['view' => $view, 'query' => $query])
                    <tbody>
                    @foreach ($slice->rows as $task)
                        <tr>
                            <td class="t-qual" title="{{ $task['runnable'] }}">
                                <span class="nm">{{ Format::leafOf($task['runnable']) }}</span>
                                <span class="ns stem">{{ Format::stemOf($task['runnable']) }}</span>
                            </td>
                            <td class="t-token dim" title="{{ $task['cron'] }}">{{ $task['cron'] ?: '—' }}</td>
                            <td class="t-num dim">{{ $task['fixedRate'] ?: '—' }}</td>
                            <td class="t-num dim">{{ $task['fixedDelay'] ?: '—' }}</td>
                            <td class="t-token dim">{{ $task['zone'] ?: '—' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @include('firefly-admin::_pager', ['slice' => $slice, 'query' => $query])
        @endif
    </div>
@endsection
