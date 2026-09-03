@extends('firefly-admin::layout')
@section('title', 'Conditions')
@section('body')
    @php
        $positive = is_array($positiveMatches ?? null) ? $positiveMatches : [];
        $negative = is_array($negativeMatches ?? null) ? $negativeMatches : [];
    @endphp

    <div class="head">
        <h1>Conditions</h1>
        <p>Conditional auto-configuration wires a capability only until you supply your own bean, then steps
           aside. Everything under <em>Backed off</em> is a decision the framework made in your favour.</p>
    </div>

    <div class="panel">
        <h2>Applied <span>{{ count($positive) }}</span></h2>
        @include('firefly-admin::_filter', ['target' => 'pos-body', 'placeholder' => 'Filter applied…'])
        @if ($positive === [])
            <p class="empty">Nothing matched.</p>
        @else
            <div class="tw">
                <table>
                    <thead><tr><th>Class</th><th>Condition</th></tr></thead>
                    <tbody id="pos-body">
                    @foreach ($positive as $row)
                        <tr>
                            <td class="mono wrapish">{{ $row['class'] ?? '' }}</td>
                            <td class="mono muted wrapish" title="{{ $row['condition'] ?? '' }}">
                                #[{{ class_basename($row['condition'] ?? '') }}]
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <div class="panel">
        <h2>Backed off <span>{{ count($negative) }}</span></h2>
        @include('firefly-admin::_filter', ['target' => 'neg-body', 'placeholder' => 'Filter backed off…'])
        @if ($negative === [])
            <p class="empty">Nothing backed off — no auto-configuration found a reason to stand down.</p>
        @else
            <div class="tw">
                <table>
                    <thead><tr><th>Class</th><th>Condition</th></tr></thead>
                    <tbody id="neg-body">
                    @foreach ($negative as $row)
                        <tr>
                            <td class="mono wrapish">{{ $row['class'] ?? '' }}</td>
                            <td class="mono muted wrapish" title="{{ $row['condition'] ?? '' }}">
                                #[{{ class_basename($row['condition'] ?? '') }}]
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endsection
