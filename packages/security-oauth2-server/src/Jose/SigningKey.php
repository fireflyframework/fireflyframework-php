<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Jose;

use Firefly\Kernel\Exception\Framework\ConfigurationException;
use OpenSSLAsymmetricKey;

/**
 * One key the server signs with or publishes: the loaded OpenSSL key, its `kid`, the algorithm it serves and
 * its public JWK. A private PEM can sign (`canSign`); a public PEM — the form a rotated-out key is usually kept
 * in — only verifies. The key TYPE is checked against the algorithm at load time (RSA for RS256, EC P-256 for
 * ES256), because php-jwt would otherwise fail on the first signature with a message that names neither.
 */
final readonly class SigningKey
{
    /**
     * @param  array<string,string>  $jwk
     */
    public function __construct(
        public string $kid,
        public string $algorithm,
        public OpenSSLAsymmetricKey $key,
        public array $jwk,
        public bool $canSign,
    ) {}

    /** The kid defaults to the RFC 7638 thumbprint, so the same key on every node publishes the same id. */
    public static function fromPem(string $pem, string $algorithm, string $kid = ''): self
    {
        $private = openssl_pkey_get_private($pem);
        $key = $private === false ? openssl_pkey_get_public($pem) : $private;
        if ($key === false) {
            throw new ConfigurationException('The OAuth2 signing key is not a PEM-encoded RSA or EC key (openssl could not load it).');
        }

        $details = openssl_pkey_get_details($key);
        if ($details === false) {
            throw new ConfigurationException('The OAuth2 signing key could not be inspected.');
        }

        $type = $details['type'] ?? null;
        $expected = $algorithm === 'ES256' ? OPENSSL_KEYTYPE_EC : OPENSSL_KEYTYPE_RSA;
        if ($type !== $expected) {
            throw new ConfigurationException(sprintf(
                'The OAuth2 signing key is a%s key but firefly.security.oauth2.server.jwt.algorithm is %s (%s); use %s or another key.',
                $type === OPENSSL_KEYTYPE_EC ? 'n EC' : ' RSA',
                $algorithm,
                $algorithm === 'ES256' ? 'an EC P-256 key' : 'an RSA key',
                $algorithm === 'ES256' ? 'RS256' : 'ES256',
            ));
        }

        $jwk = Jwk::fromDetails($details, $kid, $algorithm);
        if ($kid === '') {
            $kid = Jwk::thumbprint($jwk);
            $jwk['kid'] = $kid;
        }

        return new self($kid, $algorithm, $key, $jwk, $private !== false);
    }
}
