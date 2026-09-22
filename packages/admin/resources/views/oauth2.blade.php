@extends('firefly-admin::layout')
@section('title', 'OAuth2 clients')
@section('body')
    @php
        // Whether the Active counts came from a per-process store (the endpoint's own
        // `authorizations.processLocal`). Defaulted rather than assumed, so a payload that does not say is
        // rendered without a caveat instead of with one nothing substantiates.
        $processLocal = ($processLocalAuthorizations ?? false) === true;
    @endphp

    <div class="head">
        <h1>OAuth2 clients</h1>
        <p>The clients registered with this application's authorization server
           @if ($issuer !== '')(issuer <code>{{ $issuer }}</code>)@endif, read in-process from the
           <code>oauth2clients</code> endpoint. Secrets are never shown.</p>
    </div>

    <div class="panel">
        @include('firefly-admin::_panel-head', [
            'title' => 'Clients', 'count' => count($clients),
            'filter' => 'oauth2-body', 'placeholder' => 'Filter by client id, grant or scope…',
        ])
        @if ($clients === [])
            @include('firefly-admin::_empty', [
                'title' => 'No clients registered',
                'body' => 'Add a block under <code>firefly.security.oauth2.server.clients</code>, or switch <code>clients.driver</code> to <code>eloquent</code> and register clients dynamically.',
            ])
        @else
            <div class="tw">
                <table>
                    <thead><tr><th>Client</th><th>Authentication</th><th>Grants</th><th>Scopes</th><th>Redirect URIs</th><th>Tokens</th><th class="num">Active</th></tr></thead>
                    <tbody id="oauth2-body">
                    @foreach ($clients as $client)
                        @php
                            // Assembled here rather than with inline @if fragments: Blade only compiles a
                            // directive that is NOT glued to a word character, so `…}}s@if(…) · PKCE@endif`
                            // silently leaves an unclosed `if` in the compiled view.
                            $issuance = [(string) ($client['accessTokenFormat'] ?? ''), ($client['accessTokenTtl'] ?? 0).'s'];
                            // `requiresProofKey` (what the endpoints enforce), never `requireProofKey` (the
                            // switch the client registered): `require_pkce` and
                            // `require_proof_key_for_public_clients` both default to on, so the registered
                            // switch reads "no PKCE" for a client whose every authorization request is in fact
                            // refused without a code_challenge — the first thing this page is opened to explain.
                            //
                            // Strictly `=== true`, because both keys are `null` for a client with no
                            // authorization_code grant: PKCE and consent are that path's rules, so a machine
                            // client sends no code_challenge and reaches no consent screen, and the endpoint
                            // says "does not apply" rather than reporting a default nothing enforces. A
                            // truthy test would print both labels beside it and send the operator checking
                            // two requirements that are not there.
                            if (($client['requiresProofKey'] ?? null) === true) {
                                $issuance[] = 'PKCE';
                            }
                            if (($client['requireAuthorizationConsent'] ?? null) === true) {
                                $issuance[] = 'consent';
                            }
                            $active = is_numeric($client['activeAuthorizations'] ?? null) ? (int) $client['activeAuthorizations'] : 0;
                            // A `0` counted in a per-process store is the one cell that reads as a fact and is
                            // not one: the workers beside this one may be holding a hundred live
                            // authorizations for this client, and an operator who reads "none" goes looking
                            // for a token endpoint that is refusing nobody. Shown as `—` — nothing counted
                            // here — while a non-zero count is kept, because that one is a floor the store
                            // can vouch for. The note under the table names the key that makes it server-wide.
                            $activeCell = $processLocal && $active === 0 ? '—' : (string) $active;
                        @endphp
                        <tr>
                            <td class="cls"><span class="nm">{{ $client['clientId'] ?? '' }}</span><span class="ns">{{ $client['clientName'] ?? '' }}</span></td>
                            <td class="mono dim">{{ implode(' ', $client['authenticationMethods'] ?? []) }}</td>
                            <td class="mono dim">{{ implode(' ', $client['grantTypes'] ?? []) }}</td>
                            <td class="mono dim">{{ implode(' ', $client['scopes'] ?? []) ?: '—' }}</td>
                            <td class="mono dim">{{ implode(' ', $client['redirectUris'] ?? []) ?: '—' }}</td>
                            <td class="dim">{{ implode(' · ', $issuance) }}</td>
                            <td class="num">{{ $activeCell }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @if ($processLocal)
                {{-- Said where the number is read, not in the release notes. Phrased as the store this
                     process RESOLVED rather than as the value of the driver key or the name of a shipped
                     class, because what the endpoint reports is the store's own processLocal() — an
                     application may bind a per-process service of its own, and then neither the key nor the
                     class says anything. --}}
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
        @endif
    </div>
@endsection
