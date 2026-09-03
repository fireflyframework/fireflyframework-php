@extends('firefly-admin::layout')
@section('title', 'Config properties')
@section('body')
    @php
        use Firefly\Admin\Format;
        // The endpoint groups DTOs under a context key, mirroring Spring's /actuator/configprops. Flatten to
        // one row per bound property so the table is scannable and filterable as a single list.
        $rows = [];
        foreach ($contexts as $context => $beans) {
            if (! is_array($beans)) { continue; }
            foreach ($beans as $name => $bean) {
                if (! is_array($bean)) { continue; }
                $prefix = is_string($bean['prefix'] ?? null) ? $bean['prefix'] : '';
                $properties = is_array($bean['properties'] ?? null) ? $bean['properties'] : [];
                foreach ($properties as $key => $value) {
                    $rows[] = [
                        'class' => is_string($name) ? $name : '',
                        'prefix' => $prefix,
                        'key' => (string) $key,
                        'value' => is_scalar($value) || $value === null
                            ? ($value === null ? 'null' : (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value))
                            : json_encode($value, JSON_UNESCAPED_SLASHES),
                    ];
                }
            }
        }
    @endphp

    <div class="head">
        <h1>Config properties</h1>
        <p>Every <code>#[ConfigProperties]</code> DTO the application bound, with the values it actually
           resolved — which is not always what the config file says, once relaxed binding and profiles apply.</p>
    </div>

    <div class="panel">
        @include('firefly-admin::_panel-head', [
            'title' => 'Bound DTOs', 'count' => count($rows),
            'filter' => 'props-body', 'placeholder' => 'Filter by class, prefix or key…',
        ])
        @if ($rows === [])
            @include('firefly-admin::_empty', [
                'title' => 'Nothing bound',
                'body' => 'Create one with <code>php artisan make:firefly-config-properties</code>, then re-run <code>firefly:cache</code> if this application boots compiled.',
            ])
        @else
            <div class="tw">
                <table>
                    <thead><tr><th>Class</th><th>Prefix</th><th>Property</th><th>Value</th></tr></thead>
                    <tbody id="props-body">
                    @foreach ($rows as $row)
                        <tr>
                            <td class="cls"><span class="nm">{{ Format::shortClass($row['class']) }}</span><span class="ns">{{ rtrim(Format::namespaceOf($row['class']), '\\') }}</span></td>
                            <td class="mono dim tight">{{ $row['prefix'] ?: '—' }}</td>
                            <td class="mono tight">{{ $row['key'] }}</td>
                            <td class="mono dim wrap">{{ $row['value'] }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endsection
