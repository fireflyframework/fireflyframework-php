@extends('firefly-admin::layout')
@section('title', 'New record')
@section('body')
    @php
        use Firefly\Admin\Format;
        $base = $settings->url('data').'?resource='.urlencode($resource->slug);
    @endphp

    <div class="head">
        <h1>New {{ strtolower($resource->label) }}</h1>
        <p>
            @if ($resource->entityClass !== null)<code>{{ Format::shortClass($resource->entityClass) }}</code> · @endif
            <a href="{{ $base }}">back to {{ strtolower($resource->label) }}</a>
        </p>
    </div>

    @if (session('data-message'))
        <p class="tip">{{ session('data-message') }}</p>
    @endif

    @if (! $writable)
        <div class="panel">
            @include('firefly-admin::_empty', [
                'title' => 'The browser is read-only',
                'body' => 'Set <code>firefly.admin.data.writable</code> to permit writes. It is a separate key from
                           <code>firefly.admin.data.enabled</code> on purpose: switching the browser on never
                           silently makes it writable.',
            ])
        </div>
    @elseif (! $resource->isEloquentBacked())
        <div class="panel">
            @include('firefly-admin::_empty', [
                'title' => 'This resource cannot be created from here',
                'body' => 'Its entity is not an Eloquent model, and a generic form cannot honour an arbitrary
                           constructor&rsquo;s invariants — a required value the form does not know about, an
                           argument order it cannot guess. Records for it belong to your own use cases. Editing
                           an existing one is refused for the same reason.',
            ])
        </div>
    @else
        <div class="panel">
            @include('firefly-admin::_panel-head', [
                'title' => 'Fields',
                'count' => count(array_filter($schema->columns, fn ($c) => $c->isEditable())),
            ])
            <form method="post" action="{{ $settings->url('data') }}" class="editor">
                @csrf
                <input type="hidden" name="resource" value="{{ $resource->slug }}">
                <input type="hidden" name="op" value="create">

                @foreach ($schema->columns as $column)
                    @continue (! $column->isEditable())
                    <label>
                        <span>{{ $column->label() }} <em>{{ $column->type }}{{ $column->nullable ? '?' : '' }}</em></span>
                        <input name="f[{{ $column->name }}]"
                               @if ($column->type === 'int' || $column->type === 'float') inputmode="decimal" @endif
                               placeholder="{{ $column->nullable ? 'optional' : 'required' }}">
                    </label>
                @endforeach

                <div class="actions">
                    <button class="go" type="submit">Create record</button>
                    <a class="act" href="{{ $base }}">Cancel</a>
                </div>
            </form>
            {{-- The identifier and any masked column are absent from the form, not disabled in it: a field
                 the browser would refuse to write is a field it should not appear to accept. --}}
            <p class="note">
                The identifier is assigned by the database, and masked columns are never written from here —
                both are omitted rather than shown and ignored.
            </p>
        </div>
    @endif
@endsection
