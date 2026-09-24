@extends('firefly-admin::layout')
@section('title', 'Environment')
@section('body')
    <div class="head">
        <h1>Environment</h1>
        <p>Resolved <code>firefly.*</code> configuration as this process sees it. Keys that look secret are
           masked by the endpoint before they reach this page.</p>
    </div>

    <div class="panel">
        @include('firefly-admin::_panel-head', [
            'title' => 'Configuration', 'count' => $slice->total, 'query' => $query,
            'placeholder' => 'Search by key or value…',
        ])
        @if ($slice->isEmpty())
            @include('firefly-admin::_empty', $query->isFiltered()
                ? ['title' => 'Nothing matches', 'body' => 'No resolved key or value contains that. <a href="'.e($query->link(['q' => null, 'page' => null])).'">Show them all</a>.']
                : ['title' => 'Nothing set', 'body' => 'No <code>firefly.*</code> configuration is present. The skeleton ships a documented reference at <code>config/firefly.php</code>.'])
        @else
            <div class="tw">
                <table class="ftable">
                    @include('firefly-admin::_table-head', ['view' => $view, 'query' => $query])
                    <tbody>
                    @foreach ($slice->rows as $entry)
                        <tr>
                            <td class="t-qual" title="{{ $entry['key'] }}">
                                <span class="nm">{{ Firefly\Admin\Format::leafOf($entry['key'], '.') }}</span>
                                <span class="ns stem">{{ Firefly\Admin\Format::stemOf($entry['key'], '.') }}</span>
                            </td>
                            <td class="t-line" title="{{ $entry['value'] }}">{{ $entry['value'] }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @include('firefly-admin::_pager', ['slice' => $slice, 'query' => $query])
        @endif
    </div>
@endsection
