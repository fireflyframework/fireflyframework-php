@extends('firefly-admin::layout')
@section('title', 'Not found')
@section('body')
    <div class="head">
        <h1>No such page</h1>
        <p>The dashboard has no page called <code>{{ $slug }}</code>. Pick one from the menu.</p>
    </div>
@endsection
