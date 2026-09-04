@extends('firefly-admin::layout')
@section('title', 'Unavailable')
@section('body')
    <div class="head"><h1>{{ $page->label }}</h1><p>{{ $page->blurb }}</p></div>
    <div class="panel">
        @include('firefly-admin::_empty', [
            'title' => 'This page has no endpoint to read',
            'body' => 'It renders the <code>'.$page->requires.'</code> actuator endpoint, which this process has not registered or has switched off. Check <code>firefly.management.endpoint.'.$page->requires.'.enabled</code>, and that the package providing it is installed.',
        ])
    </div>
@endsection
