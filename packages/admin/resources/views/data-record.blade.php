@extends('firefly-admin::layout')
@section('title', 'Record')
@section('body')
    @php
        use Firefly\Admin\Data\DataColumn;
        use Firefly\Admin\Format;

        $resource = $record->resource;
        $base = $settings->url('data').'?resource='.urlencode($resource->slug);
    @endphp

    <div class="head">
        <h1>{{ $resource->label }} <span class="dim mono" style="font-size:15px">#{{ $record->id }}</span></h1>
        <p>
            @if ($resource->entityClass !== null)<code>{{ Format::shortClass($resource->entityClass) }}</code> · @endif
            <a href="{{ $base }}">back to {{ strtolower($resource->label) }}</a>
        </p>
    </div>

    @if (session('data-message'))
        <p class="tip">{{ session('data-message') }}</p>
    @endif

    <div class="panel">
        @include('firefly-admin::_panel-head', ['title' => 'Fields', 'count' => count($record->fields)])
        <div class="tw">
            <table>
                <thead><tr><th>Field</th><th>Value</th><th>Type</th></tr></thead>
                <tbody>
                @foreach ($record->fields as $name => $value)
                    @php $column = $record->schema->column((string) $name); @endphp
                    <tr>
                        <td class="mono tight">{{ $name }}@if ($column?->identifier)<span class="dim"> · id</span>@endif</td>
                        <td class="mono wrap text">
                            @if ($value === null)
                                <span class="dim">null</span>
                            @elseif ($column?->type === DataColumn::TYPE_BOOL)
                                {{ ((int) $value) === 1 ? 'true' : 'false' }}
                            @else
                                {{ is_scalar($value) ? (string) $value : (string) json_encode($value, JSON_UNESCAPED_SLASHES) }}
                            @endif
                        </td>
                        <td class="mono dim tight">{{ $column?->type ?? '—' }}{{ $column?->nullable ? '?' : '' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>

    @if ($writable)
        <div class="panel">
            @include('firefly-admin::_panel-head', ['title' => 'Edit', 'count' => count($record->schema->columns) - 1])
            <form method="post" action="{{ $settings->url('data') }}" class="editor">
                @csrf
                <input type="hidden" name="resource" value="{{ $resource->slug }}">
                <input type="hidden" name="id" value="{{ $record->id }}">
                <input type="hidden" name="op" value="update">

                @foreach ($record->schema->columns as $column)
                    @continue (! $column->isEditable())
                    <label>
                        <span>{{ $column->label() }} <em>{{ $column->type }}{{ $column->nullable ? '?' : '' }}</em></span>
                        <input name="f[{{ $column->name }}]" value="{{ is_scalar($record->fields[$column->name] ?? null) ? (string) $record->fields[$column->name] : '' }}"
                               @if ($column->sensitive) placeholder="masked — leave blank to keep" @endif>
                    </label>
                @endforeach

                <div class="actions">
                    <button class="go" type="submit">Save changes</button>
                </div>
            </form>
        </div>

        <form method="post" action="{{ $settings->url('data') }}"
              onsubmit="return confirm('Delete this record permanently?')" style="margin-top:16px">
            @csrf
            <input type="hidden" name="resource" value="{{ $resource->slug }}">
            <input type="hidden" name="id" value="{{ $record->id }}">
            <input type="hidden" name="op" value="delete">
            <button class="act danger" type="submit">Delete this record</button>
        </form>
    @else
        <p class="note">Read-only. Set <code>firefly.admin.data.writable</code> to allow edits and deletes.
        Creating records is deliberately not offered: a generic form cannot honour an entity's constructor
        invariants, and one that silently bypassed them would be worse than not having it.</p>
    @endif
@endsection
