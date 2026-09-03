@extends('firefly-admin::layout')
@section('title', 'Environment')
@section('body')
    <div class="head">
        <h1>Environment</h1>
        <p>Resolved <code>firefly.*</code> configuration as this process sees it. Values whose key looks
           secret are masked by the endpoint before they reach this page.</p>
    </div>

    <div class="panel">
        <h2>Configuration <span>{{ count($env) }} keys</span></h2>
        @include('firefly-admin::_filter', ['target' => 'env-body', 'placeholder' => 'Filter by key or value…'])
        @if ($env === [])
            <p class="empty">Nothing set under <code>firefly</code>.</p>
        @else
            <div class="tw">
                <table>
                    <thead><tr><th>Key</th><th>Value</th></tr></thead>
                    <tbody id="env-body">
                    @foreach ($env as $key => $value)
                        <tr>
                            <td class="mono wrapish">{{ $key }}</td>
                            <td class="mono muted wrapish">{{ $value }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endsection
