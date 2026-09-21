<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Jose;

use Firebase\JWT\JWK;
use Firebase\JWT\Key;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Security\OAuth2\Server\Settings\AuthorizationServerSettings;

/**
 * Every key the server knows, loaded ONCE at boot: the current key it signs with and the previous keys it still
 * verifies with and publishes (key rotation: generate a new key, move the old one — its public half is enough —
 * to `jwt.previous_keys`, deploy; tokens signed by the old key keep verifying until they expire).
 *
 * `signing_key` and every previous `key` are PEM text when they start with `-----BEGIN`, otherwise a file path
 * read once. An empty signing key is refused with the command that generates one — the server cannot issue a
 * single token without it, so the first request is not the place to find out.
 */
final class JwtSigningKeys
{
    /** @var array<string,Key>|null */
    private ?array $verification = null;

    /**
     * @param  list<SigningKey>  $previous
     */
    public function __construct(
        private readonly SigningKey $current,
        private readonly array $previous = [],
    ) {
        if (! $current->canSign) {
            throw new ConfigurationException('firefly.security.oauth2.server.jwt.signing_key must be a private key: the one configured only verifies.');
        }
    }

    public static function fromSettings(AuthorizationServerSettings $settings): self
    {
        if ($settings->signingKey === '') {
            throw new ConfigurationException(
                'firefly.security.oauth2.server.jwt.signing_key is empty: the authorization server cannot issue tokens without a '
                .'private key. Run `php artisan firefly:oauth2:keys` and set FIREFLY_OAUTH2_SERVER_SIGNING_KEY to the file it writes.'
            );
        }

        $current = SigningKey::fromPem(self::material($settings->signingKey), $settings->algorithm, $settings->keyId);

        $previous = [];
        foreach ($settings->previousKeys as $entry) {
            $previous[] = SigningKey::fromPem(self::material($entry['key']), $settings->algorithm, $entry['key_id']);
        }

        return new self($current, $previous);
    }

    /** PEM text as it is; anything else is a path to a PEM file, refused when it cannot be read. */
    public static function material(string $pemOrPath): string
    {
        if (str_starts_with(ltrim($pemOrPath), '-----BEGIN')) {
            return $pemOrPath;
        }

        if (! is_file($pemOrPath) || ! is_readable($pemOrPath)) {
            throw new ConfigurationException("The OAuth2 signing key file [{$pemOrPath}] does not exist or is not readable.");
        }

        return (string) file_get_contents($pemOrPath);
    }

    public function current(): SigningKey
    {
        return $this->current;
    }

    /**
     * @return list<SigningKey>
     */
    public function previous(): array
    {
        return $this->previous;
    }

    /**
     * The JWKS document: current key first, previous keys after — the order the JWKS endpoint publishes.
     *
     * @return array{keys: list<array<string,string>>}
     */
    public function jwks(): array
    {
        $keys = [$this->current->jwk];
        foreach ($this->previous as $key) {
            $keys[] = $key->jwk;
        }

        return ['keys' => $keys];
    }

    /**
     * The php-jwt key map (kid => Key) the server verifies ITS OWN tokens with — exactly what a resource server
     * derives from the published JWKS, so a token that verifies here verifies there.
     *
     * @return array<string,Key>
     */
    public function verificationKeys(): array
    {
        if ($this->verification === null) {
            /** @var array<string,Key> $parsed */
            $parsed = JWK::parseKeySet($this->jwks());
            $this->verification = $parsed;
        }

        return $this->verification;
    }
}
