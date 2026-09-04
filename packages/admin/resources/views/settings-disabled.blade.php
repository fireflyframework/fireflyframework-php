@extends('firefly-admin::layout')
@section('title', 'Not found')
@section('body')
    <div class="head"><h1>Not found</h1></div>
    <div class="panel">
        @include('firefly-admin::_empty', [
            'title' => 'The feature-switch console is switched off',
            'body' => 'It is off by default, unlike every other page here — the others describe the application
                       and this one changes it. Set <code>firefly.admin.settings.enabled</code> to see it, and
                       <code>firefly.admin.settings.writable</code> on top of that to get controls. Neither
                       does anything in production, where writes are refused whatever the configuration says.',
        ])
    </div>
@endsection
