@extends('firefly-admin::layout')
@section('title', 'Beans')
@section('body')
    @php use Firefly\Admin\Format; @endphp

    <div class="head">
        <h1>Beans</h1>
        <p>Every bean the container registered, with the stereotype that declared it and the scope it lives in.</p>
    </div>

    <div class="panel">
        @include('firefly-admin::_panel-head', [
            'title' => 'Container', 'count' => count($beans),
            'filter' => 'beans-body', 'placeholder' => 'Filter by class, stereotype or interface…',
        ])
        @if ($beans === [])
            @include('firefly-admin::_empty', [
                'title' => 'No beans registered',
                'body' => 'Check <code>firefly.scan.paths</code> points at your application namespace.',
            ])
        @else
            <div class="tw">
                <table>
                    <thead><tr><th>Class</th><th>Stereotype</th><th>Scope</th><th>Name</th><th>Implements</th></tr></thead>
                    <tbody id="beans-body">
                    @foreach ($beans as $bean)
                        @php $class = is_string($bean['class'] ?? null) ? $bean['class'] : ''; @endphp
                        <tr>
                            <td class="cls"><span class="nm">{{ Format::shortClass($class) }}</span><span class="ns">{{ rtrim(Format::namespaceOf($class), '\\') }}</span></td>
                            <td class="mono dim tight">{{ $bean['stereotype'] ?? '' }}</td>
                            <td class="mono dim tight">{{ $bean['scope'] ?? '' }}</td>
                            <td class="mono dim tight">{{ $bean['name'] ?: '—' }}</td>
                            <td class="mono dim wrap">
                                @php $interfaces = is_array($bean['interfaces'] ?? null) ? $bean['interfaces'] : []; @endphp
                                @forelse ($interfaces as $interface)
                                    <div>{{ Format::shortClass((string) $interface) }}</div>
                                @empty
                                    —
                                @endforelse
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endsection
