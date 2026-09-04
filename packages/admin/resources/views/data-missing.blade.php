@extends('firefly-admin::layout')
@section('title', 'Browse data')
@section('body')
    <div class="head"><h1>Not found</h1></div>
    <div class="panel">
        @include('firefly-admin::_empty', [
            'title' => 'No such record',
            'body' => 'It may have been deleted, or the resource <code>'.e($slug).'</code> has no identifier column to address one by.',
        ])
    </div>
    <p class="note"><a href="{{ $settings->url('data') }}">Back to the resource list</a></p>
@endsection
