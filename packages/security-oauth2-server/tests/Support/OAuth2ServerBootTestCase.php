<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Tests\Support;

use Firefly\Cqrs\CqrsServiceProvider;
use Firefly\Cqrs\CqrsWiringProvider;
use Firefly\Security\OAuth2\Server\Jose\KeyPairGenerator;
use Firefly\Security\OAuth2\Server\SecurityOAuth2ServerServiceProvider;
use Firefly\Security\OAuth2\Server\SecurityOAuth2ServerWiringProvider;
use Firefly\Security\SecurityServiceProvider;
use Firefly\Security\SecurityWiringProvider;
use Firefly\Testing\FireflyTestCase;
use Firefly\Validation\ValidationServiceProvider;
use Firefly\Web\WebServiceProvider;

/**
 * The smallest REAL boot of the server: web + cqrs (security's master-gated beans consume the HandlerManifest)
 * + security + this package under Testbench (a session driver, a router and an HTTP kernel exist, so
 * SessionSecurityBootstrap can install the session middleware), the master flag and session security on, the
 * server on. Subclasses add keys through serverOverrides(), read before boot. Not `final`: Pest's uses()
 * generates a per-file class that extends it.
 */
class OAuth2ServerBootTestCase extends FireflyTestCase
{
    private static ?string $signingKey = null;

    /** One RSA key per process: generating a 2048-bit key per test would cost seconds for nothing. */
    public static function signingKey(): string
    {
        return self::$signingKey ??= KeyPairGenerator::generate('RS256');
    }

    protected function fireflyProviders(): array
    {
        return [
            ValidationServiceProvider::class,
            WebServiceProvider::class,
            CqrsServiceProvider::class,
            CqrsWiringProvider::class,
            SecurityServiceProvider::class,
            SecurityWiringProvider::class,
            SecurityOAuth2ServerServiceProvider::class,
            SecurityOAuth2ServerWiringProvider::class,
        ];
    }

    /** @return array<string, mixed> extra firefly.security.oauth2.server.* keys, seeded before boot */
    protected function serverOverrides(): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    protected function configOverrides(): array
    {
        return [
            'app.url' => 'http://localhost',
            'session.driver' => 'array',
            'firefly.security.enabled' => true,
            'firefly.security.session.enabled' => true,
            'firefly.security.oauth2.server.enabled' => true,
            'firefly.security.oauth2.server.jwt.signing_key' => self::signingKey(),
            ...$this->serverOverrides(),
        ];
    }
}
