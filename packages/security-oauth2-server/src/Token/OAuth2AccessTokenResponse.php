<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Token;

use Illuminate\Http\JsonResponse;

/** The RFC 6749 §5.1 token response (plus `id_token`, OpenID Connect Core §3.1.3.3), always `Cache-Control: no-store`. */
final readonly class OAuth2AccessTokenResponse
{
    /**
     * @param  list<string>  $scopes
     */
    public function __construct(
        public string $accessToken,
        public int $expiresIn,
        public array $scopes,
        public ?string $refreshToken = null,
        public ?string $idToken = null,
        public string $tokenType = 'Bearer',
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $body = ['access_token' => $this->accessToken, 'token_type' => $this->tokenType, 'expires_in' => $this->expiresIn, 'scope' => implode(' ', $this->scopes)];
        if ($this->refreshToken !== null) {
            $body['refresh_token'] = $this->refreshToken;
        }
        if ($this->idToken !== null) {
            $body['id_token'] = $this->idToken;
        }

        return $body;
    }

    public function toResponse(): JsonResponse
    {
        return new JsonResponse($this->toArray(), 200, ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
    }
}
