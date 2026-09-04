@extends('firefly-admin::layout')
@section('title', 'Conditions')
@section('body')
    @php
        use Firefly\Admin\Format;
        $positive = is_array($positiveMatches ?? null) ? $positiveMatches : [];
        $negative = is_array($negativeMatches ?? null) ? $negativeMatches : [];
    @endphp

    <div class="head">
        <h1>Conditions</h1>
        <p>Auto-configuration wires a capability only until you supply your own bean, then steps aside.
           Everything under <em>Backed off</em> is a decision the framework made in your favour.</p>
    </div>

    <div class="grid two">
        @foreach ([['Applied', $positive, 'pos-body'], ['Backed off', $negative, 'neg-body']] as [$title, $rows, $id])
            <div class="panel">
                @include('firefly-admin::_panel-head', [
                    'title' => $title, 'count' => count($rows),
                    'filter' => $id, 'placeholder' => 'Filter…',
                ])
                @if ($rows === [])
                    @include('firefly-admin::_empty', [
                        'title' => 'Nothing here',
                        'body' => $title === 'Applied'
                            ? 'No condition matched — unusual, and worth checking that auto-configuration is discovering your packages.'
                            : 'No auto-configuration found a reason to stand down. Every capability is running its framework default.',
                    ])
                @else
                    <div class="tw">
                        <table>
                            <thead><tr><th>Class</th><th>Condition</th></tr></thead>
                            <tbody id="{{ $id }}">
                            @foreach ($rows as $row)
                                @php $class = is_string($row['class'] ?? null) ? $row['class'] : ''; @endphp
                                <tr>
                                    <td class="cls"><span class="nm">{{ Format::shortClass($class) }}</span><span class="ns">{{ rtrim(Format::namespaceOf($class), '\\') }}</span></td>
                                    <td class="mono dim tight" title="{{ $row['condition'] ?? '' }}">#[{{ Format::shortClass((string) ($row['condition'] ?? '')) }}]</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        @endforeach
    </div>
@endsection
