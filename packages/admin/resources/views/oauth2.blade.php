@extends('firefly-admin::layout')
@section('title', 'OAuth2 clients')
@section('body')
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
                            if ($client['requiresProofKey'] ?? false) {
                                $issuance[] = 'PKCE';
                            }
                            if ($client['requireAuthorizationConsent'] ?? false) {
                                $issuance[] = 'consent';
                            }
                        @endphp
                        <tr>
                            <td class="cls"><span class="nm">{{ $client['clientId'] ?? '' }}</span><span class="ns">{{ $client['clientName'] ?? '' }}</span></td>
                            <td class="mono dim">{{ implode(' ', $client['authenticationMethods'] ?? []) }}</td>
                            <td class="mono dim">{{ implode(' ', $client['grantTypes'] ?? []) }}</td>
                            <td class="mono dim">{{ implode(' ', $client['scopes'] ?? []) ?: '—' }}</td>
                            <td class="mono dim">{{ implode(' ', $client['redirectUris'] ?? []) ?: '—' }}</td>
                            <td class="dim">{{ implode(' · ', $issuance) }}</td>
                            <td class="num">{{ $client['activeAuthorizations'] ?? 0 }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endsection
