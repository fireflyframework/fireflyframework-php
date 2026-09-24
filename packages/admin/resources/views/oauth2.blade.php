@extends('firefly-admin::layout')
@section('title', 'OAuth2 clients')
@section('body')
    @php
        // Whether the Active counts came from a per-process store (the endpoint's own
        // `authorizations.processLocal`), decided in AdminAction and carried here for the note below.
        $processLocal = $processLocalAuthorizations;
    @endphp

    <div class="head">
        <h1>OAuth2 clients</h1>
        <p>The clients registered with this application's authorization server
           @if ($issuer !== '')(issuer <code>{{ $issuer }}</code>)@endif, read in-process from the
           <code>oauth2clients</code> endpoint. Secrets are never shown.</p>
    </div>

    <div class="panel">
        @include('firefly-admin::_panel-head', [
            'title' => 'Clients', 'count' => $slice->total, 'query' => $query,
            'placeholder' => 'Search by client id, grant or scope…',
        ])
        @if ($slice->isEmpty())
            @include('firefly-admin::_empty', $query->isFiltered()
                ? ['title' => 'Nothing matches', 'body' => 'No client id, name, grant or scope contains that. <a href="'.e($query->link(['q' => null, 'page' => null])).'">Show them all</a>.']
                : ['title' => 'No clients registered', 'body' => 'Add a block under <code>firefly.security.oauth2.server.clients</code>, or switch <code>clients.driver</code> to <code>eloquent</code> and register clients dynamically.'])
        @else
            <div class="tw">
                <table class="ftable">
                    @include('firefly-admin::_table-head', ['view' => $view, 'query' => $query])
                    <tbody id="oauth2-body">
                    @foreach ($slice->rows as $client)
                        <tr>
                            {{-- The client name is `class="ns"` WITHOUT `stem`: it is a human name, not a
                                 qualified prefix, so it elides from the right like any prose rather than
                                 from the left like a namespace. --}}
                            <td class="t-qual" title="{{ $client['clientId'] }}">
                                <span class="nm">{{ $client['clientId'] }}</span>
                                <span class="ns">{{ $client['clientName'] }}</span>
                            </td>
                            <td class="t-token dim" title="{{ $client['authentication'] }}">{{ $client['authentication'] ?: '—' }}</td>
                            <td class="t-token dim" title="{{ $client['grants'] }}">{{ $client['grants'] ?: '—' }}</td>
                            <td class="t-token dim" title="{{ $client['scopes'] }}">{{ $client['scopes'] ?: '—' }}</td>
                            <td class="t-line" title="{{ $client['redirects'] }}">{{ $client['redirects'] ?: '—' }}</td>
                            <td class="t-token dim" title="{{ $client['issuance'] }}">{{ $client['issuance'] }}</td>
                            <td class="t-num">{{ $client['active'] }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @include('firefly-admin::_pager', ['slice' => $slice, 'query' => $query])
        @endif
        @if ($processLocal)
            {{-- Said where the number is read, not in the release notes. Phrased as the store this
                 process RESOLVED rather than as the value of the driver key or the name of a shipped
                 class, because what the endpoint reports is the store's own processLocal() — an
                 application may bind a per-process service of its own, and then neither the key nor the
                 class says anything.

                 OUTSIDE the @if on the listing, because a narrowed search that matches nothing does not
                 make the caveat untrue: the page is still counting one worker's authorizations, and a
                 reader who has just typed a term is exactly the reader about to conclude something from
                 an Active column they cannot see. --}}
            <p class="note"><strong>Active counts this worker only.</strong> The authorization store this
               process resolved keeps its authorizations in the process — a map rebuilt in every PHP
               worker — so these are the ones held by the worker that rendered this page, and under
               php-fpm or Octane the next request lands on a different one. A client with live tokens
               elsewhere therefore shows <code>—</code> here. Set
               <code>firefly.security.oauth2.server.authorizations.driver</code> to <code>eloquent</code>
               (and run the <code>oauth2_authorizations</code> migration), or bind a durable
               <code>OAuth2AuthorizationService</code> of your own, for counts that describe the
               deployment.</p>
        @endif
    </div>
@endsection
