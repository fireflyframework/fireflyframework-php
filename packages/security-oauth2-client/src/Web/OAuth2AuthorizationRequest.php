<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Client\Web;

/**
 * One authorization request (Spring's OAuth2AuthorizationRequest): what the browser is sent to the provider
 * with, and what the callback is checked against. It is stored in the session BETWEEN the two, which is what
 * binds `state` to the session it was minted in and what keeps the PKCE verifier and the nonce where only this
 * application can read them. Immutable and serialisable — scalars and lists only.
 *
 * toUri() writes the RFC 6749 §4.1.1 parameters (`response_type=code`, `client_id`, `redirect_uri`, `state`,
 * `scope`), the OIDC `nonce` when one was minted, and the RFC 7636 `code_challenge`/`code_challenge_method=S256`
 * when a verifier was — RFC 3986 encoded, so a scope list is `openid%20profile`, which every provider reads.
 */
final readonly class OAuth2AuthorizationRequest
{
    /**
     * @param  list<string>  $scopes
     * @param  array<string, string>  $additionalParameters
     */
    public function __construct(
        public string $registrationId,
        public string $authorizationUri,
        public string $clientId,
        public string $redirectUri,
        public array $scopes,
        public string $state,
        public ?string $nonce = null,
        public ?string $codeVerifier = null,
        public array $additionalParameters = [],
    ) {}

    public function toUri(): string
    {
        $query = [
            'response_type' => 'code',
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'state' => $this->state,
        ];
        if ($this->scopes !== []) {
            $query['scope'] = implode(' ', $this->scopes);
        }
        if ($this->nonce !== null) {
            $query['nonce'] = $this->nonce;
        }
        if ($this->codeVerifier !== null) {
            $query['code_challenge'] = self::codeChallenge($this->codeVerifier);
            $query['code_challenge_method'] = 'S256';
        }
        $query = [...$query, ...$this->additionalParameters];

        return $this->authorizationUri
            .(str_contains($this->authorizationUri, '?') ? '&' : '?')
            .http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /** RFC 7636 §4.2: BASE64URL(SHA256(code_verifier)), without padding. */
    public static function codeChallenge(string $codeVerifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
    }
}
