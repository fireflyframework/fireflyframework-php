@extends('firefly-admin::layout')
@section('title', 'Beans')
@section('body')
    <div class="head">
        <h1>Beans</h1>
        <p>Every bean the container registered, with the stereotype that declared it and the scope it lives in.</p>
    </div>

    <div class="panel">
        <h2>Container <span>{{ count($beans) }} beans</span></h2>
        @include('firefly-admin::_filter', ['target' => 'beans-body', 'placeholder' => 'Filter by class, stereotype or interface…'])
        @if ($beans === [])
            <p class="empty">No beans registered.</p>
        @else
            <div class="tw">
                <table>
                    <thead><tr><th>Class</th><th>Stereotype</th><th>Scope</th><th>Name</th><th>Implements</th></tr></thead>
                    <tbody id="beans-body">
                    @foreach ($beans as $bean)
                        <tr>
                            <td class="mono wrapish">{{ $bean['class'] ?? '' }}</td>
                            <td class="mono muted">{{ $bean['stereotype'] ?? '' }}</td>
                            <td class="mono muted">{{ $bean['scope'] ?? '' }}</td>
                            <td class="mono muted">{{ $bean['name'] ?: '—' }}</td>
                            <td class="mono muted wrapish">
                                {{ is_array($bean['interfaces'] ?? null) && $bean['interfaces'] !== [] ? implode(', ', $bean['interfaces']) : '—' }}
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endsection
