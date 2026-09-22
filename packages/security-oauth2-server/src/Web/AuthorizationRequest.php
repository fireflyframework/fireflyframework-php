<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Web;

use Illuminate\Http\Request;

/**
 * The parameters of an authorization request (RFC 6749 §4.1.1, RFC 7636 §4.3, OpenID Connect Core §3.1.2.1),
 * read from the query string as they came: nothing is validated here — the endpoint decides, in RFC order,
 * what is unredirectable, what is an error redirect and what proceeds.
 */
final readonly class AuthorizationRequest
{
    /**
     * @param  list<string>  $prompt
     */
    public function __construct(
        public string $clientId,
        public ?string $redirectUri,
        public ?string $responseType,
        public ?string $scope,
        public ?string $state,
        public ?string $codeChallenge,
        public ?string $codeChallengeMethod,
        public ?string $nonce,
        public array $prompt,
        public ?int $maxAge,
        public bool $maxAgeMalformed,
    ) {}

    public static function from(Request $request): self
    {
        $maxAge = self::string($request, 'max_age');
        $malformed = $maxAge !== null && preg_match('/^\d+$/', $maxAge) !== 1;
        $prompt = self::string($request, 'prompt');

        return new self(
            clientId: self::string($request, 'client_id') ?? '',
            redirectUri: self::string($request, 'redirect_uri'),
            responseType: self::string($request, 'response_type'),
            scope: self::string($request, 'scope'),
            state: self::string($request, 'state'),
            codeChallenge: self::string($request, 'code_challenge'),
            codeChallengeMethod: self::string($request, 'code_challenge_method'),
            nonce: self::string($request, 'nonce'),
            prompt: $prompt === null ? [] : array_values(array_filter(preg_split('/\s+/', $prompt) ?: [], static fn (string $p): bool => $p !== '')),
            maxAge: $malformed || $maxAge === null ? null : (int) $maxAge,
            maxAgeMalformed: $malformed,
        );
    }

    /**
     * @return list<string>
     */
    public function scopes(): array
    {
        if ($this->scope === null) {
            return [];
        }

        return array_values(array_unique(array_filter(preg_split('/\s+/', $this->scope) ?: [], static fn (string $s): bool => $s !== '')));
    }

    public function prompts(string $value): bool
    {
        return in_array($value, $this->prompt, true);
    }

    private static function string(Request $request, string $name): ?string
    {
        $value = $request->query($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
