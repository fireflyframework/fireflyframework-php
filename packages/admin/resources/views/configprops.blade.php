@extends('firefly-admin::layout')
@section('title', 'Config properties')
@section('body')
    @php
        use Firefly\Admin\Format;

        // The endpoint answers one row per #[ConfigProperties] DTO. Flattening to one row per PROPERTY makes
        // the table filterable as a single list, which is how someone actually looks a value up; the
        // unbound rows are kept separately because "it did not bind, and here is why" is the more urgent
        // thing this page can tell you.
        $rows = [];
        $problems = [];
        foreach ($beans as $class => $bean) {
            if (! is_array($bean)) { continue; }
            $class = is_string($bean['class'] ?? null) ? $bean['class'] : (string) $class;
            $prefix = is_string($bean['prefix'] ?? null) ? $bean['prefix'] : '';
            $bound = ($bean['bound'] ?? false) === true;
            $error = is_string($bean['error'] ?? null) ? $bean['error'] : null;
            $profiles = is_array($bean['profiles'] ?? null) ? $bean['profiles'] : [];

            if (! $bound) {
                $problems[] = ['class' => $class, 'prefix' => $prefix, 'profiles' => $profiles, 'error' => $error];
                continue;
            }

            foreach (is_array($bean['properties'] ?? null) ? $bean['properties'] : [] as $key => $value) {
                $rows[] = [
                    'class' => $class,
                    'prefix' => $prefix,
                    'key' => (string) $key,
                    'value' => match (true) {
                        is_bool($value) => $value ? 'true' : 'false',
                        $value === null => 'null',
                        is_scalar($value) => (string) $value,
                        default => (string) json_encode($value, JSON_UNESCAPED_SLASHES),
                    },
                ];
            }
        }
    @endphp

    <div class="head">
        <h1>Config properties</h1>
        <p>Every <code>#[ConfigProperties]</code> DTO the application bound, with the values it actually
           resolved — which is not always what the file says, once relaxed binding and profiles apply.</p>
    </div>

    @if ($problems !== [])
        <div class="panel">
            @include('firefly-admin::_panel-head', ['title' => 'Not bound', 'count' => count($problems)])
            <div class="tw">
                <table>
                    <thead><tr><th>Class</th><th>Prefix</th><th>Why</th></tr></thead>
                    <tbody>
                    @foreach ($problems as $problem)
                        <tr>
                            <td class="cls"><span class="nm">{{ Format::shortClass($problem['class']) }}</span><span class="ns">{{ rtrim(Format::namespaceOf($problem['class']), '\\') }}</span></td>
                            <td class="mono dim tight">{{ $problem['prefix'] ?: '—' }}</td>
                            <td class="dim wrap">
                                @if ($problem['error'] !== null)
                                    {{ $problem['error'] }}
                                @elseif ($problem['profiles'] !== [])
                                    Requires the {{ implode(', ', $problem['profiles']) }} profile, which is not active.
                                @else
                                    Not bound on this boot.
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <div class="panel">
        @include('firefly-admin::_panel-head', [
            'title' => 'Bound values', 'count' => count($rows),
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

    <p class="note">Values that look secret are masked by the endpoint before they reach this page — the key
    decides, so a sensitive key holding an array is replaced whole rather than descended into.</p>
@endsection
