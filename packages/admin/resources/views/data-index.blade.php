@extends('firefly-admin::layout')
@section('title', 'Browse data')
@section('body')
    @php use Firefly\Admin\Format; @endphp

    <div class="head">
        <h1>Browse data</h1>
        <p>Every repository this application declared. The browser reads through the repositories themselves,
           so what you see here is what your own data layer returns — not a raw table dump.</p>
    </div>

    <div class="panel">
        @include('firefly-admin::_panel-head', [
            'title' => 'Resources', 'count' => count($resources),
            'filter' => 'res-body', 'placeholder' => 'Filter resources…',
        ])
        @if ($resources === [])
            @include('firefly-admin::_empty', [
                'title' => 'No repositories found',
                'body' => 'A resource is a bean implementing <code>Firefly\Data\Repository\CrudRepository</code>. Create one with <code>php artisan make:firefly-repository</code>, then re-run <code>firefly:cache</code> if this application boots compiled.',
            ])
        @else
            <div class="tw">
                <table>
                    <thead><tr><th>Resource</th><th>Entity</th><th>Table</th><th>Paging</th><th></th></tr></thead>
                    <tbody id="res-body">
                    @foreach ($resources as $resource)
                        <tr>
                            <td class="cls">
                                <span class="nm"><a href="{{ $settings->url('data') }}?resource={{ urlencode($resource->slug) }}">{{ $resource->label }}</a></span>
                                <span class="ns">{{ rtrim(Format::namespaceOf($resource->repositoryClass), '\\') }}</span>
                            </td>
                            <td class="mono dim">{{ $resource->entityClass !== null ? Format::shortClass($resource->entityClass) : '—' }}</td>
                            <td class="mono dim">{{ $resource->table ?: '—' }}</td>
                            <td class="tight">
                                <span class="chip flat">{{ $resource->paged ? 'paged' : 'in-memory' }}</span>
                            </td>
                            <td class="tight"><a href="{{ $settings->url('data') }}?resource={{ urlencode($resource->slug) }}">Browse &rarr;</a></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <p class="note">A repository that does not implement <code>PagingAndSortingRepository</code> is listed as
    <em>in-memory</em>: the browser has to call <code>findAll()</code> and slice the result in PHP, which is
    fine for a lookup table and a foot-gun for a large one.</p>
@endsection
