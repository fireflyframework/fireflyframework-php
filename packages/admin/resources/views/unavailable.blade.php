@extends('firefly-admin::layout')
@section('title', 'Unavailable')
@section('body')
    <div class="head">
        <h1>{{ $page->label }} is not available</h1>
        <p>{{ $page->blurb }}</p>
    </div>
    <p class="note">This page reads the <code>{{ $page->requires }}</code> actuator endpoint, which this
    process has not registered or has switched off. Check
    <code>firefly.management.endpoint.{{ $page->requires }}.enabled</code>, and that the package providing it
    is installed.</p>
@endsection
