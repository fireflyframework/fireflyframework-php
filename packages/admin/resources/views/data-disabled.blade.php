@extends('firefly-admin::layout')
@section('title', 'Browse data')
@section('body')
    <div class="head"><h1>Browse data</h1></div>
    <div class="panel">
        @include('firefly-admin::_empty', [
            'title' => 'The data browser is off',
            'body' => 'It reads the records behind your repositories, which is a far bigger disclosure than beans or configuration — so it is off even when the rest of the dashboard is on. Set <code>firefly.admin.data.enabled</code> to true to switch it on, and <code>firefly.admin.data.writable</code> on top of that to allow edits and deletes.',
        ])
    </div>
@endsection
