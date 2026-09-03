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
            'title' => 'Configuration', 'count' => count($env),
            'filter' => 'env-body', 'placeholder' => 'Filter by key or value…',
        ])
        @if ($env === [])
            @include('firefly-admin::_empty', [
                'title' => 'Nothing set',
                'body' => 'No <code>firefly.*</code> configuration is present. The skeleton ships a documented reference at <code>config/firefly.php</code>.',
            ])
        @else
            <div class="tw">
                <table>
                    <thead><tr><th>Key</th><th>Value</th></tr></thead>
                    <tbody id="env-body">
                    @foreach ($env as $key => $value)
                        <tr>
                            <td class="mono wrap">{{ $key }}</td>
                            <td class="mono dim wrap">{{ $value }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endsection
