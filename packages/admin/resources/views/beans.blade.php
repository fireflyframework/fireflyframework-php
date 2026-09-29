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
            'title' => 'Container', 'count' => $slice->total, 'query' => $query,
            'placeholder' => 'Search by class, stereotype or interface…',
        ])
        @if ($slice->isEmpty())
            @include('firefly-admin::_empty', $query->isFiltered()
                ? ['title' => 'Nothing matches', 'body' => 'No bean\'s class, stereotype, scope, name or interfaces contain that. <a href="'.e($query->link(['q' => null, 'page' => null])).'">Show them all</a>.']
                : ['title' => 'No beans registered', 'body' => 'Check <code>firefly.scan.paths</code> points at your application namespace.'])
        @else
            <div class="tw">
                <table class="ftable">
                    @include('firefly-admin::_table-head', ['view' => $view, 'query' => $query])
                    <tbody>
                    @foreach ($slice->rows as $bean)
                        <tr>
                            <td class="t-qual" title="{{ $bean['class'] }}">
                                <span class="nm">{{ Format::leafOf($bean['class']) }}</span>
                                <span class="ns stem">{{ Format::stemOf($bean['class']) }}</span>
                            </td>
                            <td class="t-token dim">{{ $bean['stereotype'] ?: '—' }}</td>
                            <td class="t-token dim">{{ $bean['scope'] ?: '—' }}</td>
                            <td class="t-token dim" title="{{ $bean['name'] }}">{{ $bean['name'] ?: '—' }}</td>
                            {{-- The leaf names in the cell and the qualified ones on the title, exactly as the
                                 Class column beside it: the searchable value is the one on hover. --}}
                            <td class="t-text dim" title="{{ $bean['interfacesQualified'] }}">{{ $bean['interfaces'] ?: '—' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @include('firefly-admin::_pager', ['slice' => $slice, 'query' => $query])
        @endif
    </div>
@endsection
