@extends('firefly-admin::layout')
@section('title', 'Config properties')
@section('body')
    @php use Firefly\Admin\Format; @endphp

    <div class="head">
        <h1>Config properties</h1>
        <p>Every <code>#[ConfigProperties]</code> DTO the application bound, with the values it actually
           resolved — which is not always what the file says, once relaxed binding and profiles apply.</p>
    </div>

    @if (! $unbound->isEmpty() || $unboundQuery->isFiltered())
        <div class="panel">
            @include('firefly-admin::_panel-head', [
                'title' => 'Not bound', 'count' => $unbound->total, 'query' => $unboundQuery,
                'placeholder' => 'Search by class, prefix or reason…',
            ])
            @if ($unbound->isEmpty())
                @include('firefly-admin::_empty', ['title' => 'Nothing matches', 'body' => 'Every unbound DTO is hidden by that search. <a href="'.e($unboundQuery->link(['q' => null, 'page' => null])).'">Show them all</a>.'])
            @else
                <div class="tw">
                    <table class="ftable">
                        @include('firefly-admin::_table-head', ['view' => $unboundView, 'query' => $unboundQuery])
                        <tbody>
                        @foreach ($unbound->rows as $problem)
                            <tr>
                                <td class="t-qual" title="{{ $problem['class'] }}">
                                    <span class="nm">{{ Format::leafOf($problem['class']) }}</span>
                                    <span class="ns stem">{{ Format::stemOf($problem['class']) }}</span>
                                </td>
                                <td class="t-token dim" title="{{ $problem['prefix'] }}">{{ $problem['prefix'] ?: '—' }}</td>
                                <td class="t-text dim">{{ $problem['why'] }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                @include('firefly-admin::_pager', ['slice' => $unbound, 'query' => $unboundQuery])
            @endif
        </div>
    @endif

    <div class="panel">
        @include('firefly-admin::_panel-head', [
            'title' => 'Bound values', 'count' => $bound->total, 'query' => $boundQuery,
            'placeholder' => 'Search by class, prefix, property or value…',
        ])
        @if ($bound->isEmpty())
            @include('firefly-admin::_empty', $boundQuery->isFiltered()
                ? ['title' => 'Nothing matches', 'body' => 'No bound property matches that. <a href="'.e($boundQuery->link(['q' => null, 'page' => null])).'">Show them all</a>.']
                : ['title' => 'Nothing bound', 'body' => 'Create one with <code>php artisan make:firefly-config-properties</code>, then re-run <code>firefly:cache</code> if this application boots compiled.'])
        @else
            <div class="tw">
                <table class="ftable">
                    @include('firefly-admin::_table-head', ['view' => $boundView, 'query' => $boundQuery])
                    <tbody>
                    @foreach ($bound->rows as $row)
                        <tr>
                            <td class="t-qual" title="{{ $row['class'] }}">
                                <span class="nm">{{ Format::leafOf($row['class']) }}</span>
                                <span class="ns stem">{{ Format::stemOf($row['class']) }}</span>
                            </td>
                            <td class="t-token dim" title="{{ $row['prefix'] }}">{{ $row['prefix'] ?: '—' }}</td>
                            <td class="t-token" title="{{ $row['key'] }}">{{ $row['key'] }}</td>
                            <td class="t-line" title="{{ $row['value'] }}">{{ $row['value'] }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @include('firefly-admin::_pager', ['slice' => $bound, 'query' => $boundQuery])
        @endif
    </div>

    <p class="note">Values that look secret are masked by the endpoint before they reach this page — the key
    decides, so a sensitive key holding an array is replaced whole rather than descended into.</p>
@endsection
