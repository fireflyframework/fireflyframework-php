<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Jose;

use Firebase\JWT\JWK as JwkSet;
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
 * single token without it, so the first request is not the place to find out. So is a kid two published keys
 * share: a verifier keeps ONE key per kid (php-jwt's JWK::parseKeySet, the last one listed — on a resource
 * server and in verificationKeys() alike), so the current key would be unreachable under its own id and every
 * token signed from then on would fail at its first use — which is why an explicit `jwt.key_id` has to change
 * with the key on a rotation (or be dropped, so the thumbprint takes over).
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

        self::assertDistinctKids($current, $previous);
    }

    /**
     * Every published key under its own kid, refused at boot otherwise. The likely mistake is a rotation that kept
     * an explicit `jwt.key_id` while the old key moved to `previous_keys` under the id tokens in flight carry: two
     * DIFFERENT keys, one kid, and the verification map holds the old one. The other is the same key listed twice
     * (a public half in `previous_keys` beside its private half in `signing_key`), which thumbprint kids collide
     * on by design; the two are told apart by the RFC 7638 thumbprint, so the sentence names the actual cure.
     *
     * @param  list<SigningKey>  $previous
     */
    private static function assertDistinctKids(SigningKey $current, array $previous): void
    {
        /** @var array<string,array{string,SigningKey}> $published kid => [where it was configured, the key] */
        $published = [$current->kid => ['jwt.signing_key', $current]];

        foreach ($previous as $index => $key) {
            $entry = "jwt.previous_keys[{$index}]";
            $holder = $published[$key->kid] ?? null;
            if ($holder === null) {
                $published[$key->kid] = [$entry, $key];

                continue;
            }

            [$where, $other] = $holder;
            if (Jwk::thumbprint($key->jwk) === Jwk::thumbprint($other->jwk)) {
                throw new ConfigurationException(sprintf(
                    'firefly.security.oauth2.server.%s is the same key as %s (kid `%s`): a key is rotated out by moving it to '
                    .'jwt.previous_keys once a NEW key has taken its place in jwt.signing_key, not by listing it twice. Remove the entry.',
                    $entry,
                    $where,
                    $key->kid,
                ));
            }

            throw new ConfigurationException(sprintf(
                'firefly.security.oauth2.server.%s and %s are different keys published under the same kid `%s`: a verifier keeps '
                .'one key per kid, so the tokens one of them signed would never verify. Give every published key its own kid — '
                .'change jwt.key_id (or leave it empty for the RFC 7638 thumbprint) or the entry\'s key_id.',
                $entry,
                $where,
                $key->kid,
            ));
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
            $parsed = JwkSet::parseKeySet($this->jwks());
            $this->verification = $parsed;
        }

        return $this->verification;
    }
}
