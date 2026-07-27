<?php

declare(strict_types=1);

use Firefly\Context\Boot\ApplicationContext;
use Firefly\Cqrs\CqrsServiceProvider;
use Firefly\Cqrs\CqrsWiringProvider;
use Firefly\Cqrs\Security\AllowAllAuthorizer;
use Firefly\Cqrs\Security\CommandAuthorizer;
use Firefly\Data\Repository\Auditing\AuditorAware;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Security\Cqrs\SecurityCommandAuthorizer;
use Firefly\Security\Data\SecurityContextAuditorAware;
use Firefly\Security\Jwt\WeakSigningSecretException;
use Firefly\Security\SecurityServiceProvider;
use Firefly\Security\SecurityWiringProvider;
use Illuminate\Foundation\Application;

/**
 * @param  array<string,mixed>  $security  the `firefly.security.*` tree for this boot
 */
function bootSecurityAppWith(array $security): Application
{
    // needs: ['cache'] — the jwksProvider #[Bean] resolves Illuminate\Contracts\Cache\Repository; the
    // harness's isolated ArrayStore fallback (fireflyApplication()'s missing-bindings menu) satisfies it
    // whenever the oauth2 resource-server surface flag is on, harmless (unused) otherwise.
    return fireflyApplication(
        config: ['firefly' => ['cqrs' => [], 'security' => $security]],
        providers: [CqrsServiceProvider::class, CqrsWiringProvider::class, SecurityServiceProvider::class, SecurityWiringProvider::class],
        needs: ['cache'],
    );
}

function bootSecurityApp(bool $enabled): Application
{
    return bootSecurityAppWith(['enabled' => $enabled]);
}

it('binds the real CommandAuthorizer + AuditorAware when security is enabled', function () {
    /** @var ApplicationContext $context */
    $context = bootSecurityApp(true)->make(ApplicationContext::class);

    expect($context->get(CommandAuthorizer::class))->toBeInstanceOf(SecurityCommandAuthorizer::class)
        ->and($context->get(AuditorAware::class))->toBeInstanceOf(SecurityContextAuditorAware::class);
});

it('leaves the AllowAll authorizer in place when security is disabled', function () {
    /** @var ApplicationContext $context */
    $context = bootSecurityApp(false)->make(ApplicationContext::class);

    expect($context->get(CommandAuthorizer::class))->toBeInstanceOf(AllowAllAuthorizer::class);
});

it('refuses to boot when both local-JWT and the OAuth2 resource server are enabled', function () {
    // Master flag deliberately OFF: jwt.enabled/oauth2.resource_server.enabled are surface flags, not
    // master-gated (see SecurityAutoConfiguration), so the conflict must be caught even without
    // firefly.security.enabled — this proves the guard is unconditional, not incidentally covered by the
    // master-flag-enabled beans. A valid (non-placeholder, >=32 byte) JWT secret and a jwks_uri are supplied
    // so the failure is unambiguously the mutual-exclusivity refusal, not JwtService's weak-secret guard or
    // JwksProvider's missing-config guard tripping first during eager singleton resolution (phase 900, which
    // runs before the WiringPasses phase this guard lives in).
    expect(fn () => bootSecurityAppWith([
        'enabled' => false,
        'jwt' => [
            'enabled' => true,
            'secret' => str_repeat('k', 40),
        ],
        'oauth2' => [
            'resource_server' => [
                'enabled' => true,
                'jwks_uri' => 'https://example.test/.well-known/jwks.json',
            ],
        ],
    ]))->toThrow(ConfigurationException::class);
});

it('refuses to boot on a weak JWT secret even when the master flag is off', function () {
    // Master flag deliberately OFF: JwtAuthenticationFilter/JwtService are gated ONLY by
    // firefly.security.jwt.enabled (see SecurityAutoConfiguration), never by firefly.security.enabled, so the
    // weak-secret fail-fast must fire at BOOT regardless of the master flag — proving SecurityWiringPass eagerly
    // resolves JwtService before, not after, the master-flag early-return.
    expect(fn () => bootSecurityAppWith([
        'enabled' => false,
        'jwt' => [
            'enabled' => true,
            'secret' => 'changeme',
        ],
    ]))->toThrow(WeakSigningSecretException::class);
});
