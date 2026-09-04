@extends('firefly-admin::layout')
@section('title', 'Not found')
@section('body')
    <div class="head"><h1>No such page</h1></div>
    <div class="panel">
        @include('firefly-admin::_empty', [
            'title' => 'The dashboard has no page called “'.$slug.'”',
            'body' => 'Pick one from the menu on the left.',
        ])
    </div>
@endsection
