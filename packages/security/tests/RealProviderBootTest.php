<?php

declare(strict_types=1);

use Firefly\Context\Boot\ApplicationContext;
use Firefly\Context\Event\ApplicationEventPublisher;
use Firefly\Context\Scan\AppScan;
use Firefly\Cqrs\CqrsServiceProvider;
use Firefly\Cqrs\CqrsWiringProvider;
use Firefly\Cqrs\Security\AllowAllAuthorizer;
use Firefly\Cqrs\Security\CommandAuthorizer;
use Firefly\Data\Repository\Auditing\AuditorAware;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Security\Access\Method\SecurityMethodManifestCompiler;
use Firefly\Security\Core\Authentication;
use Firefly\Security\Cqrs\SecurityCommandAuthorizer;
use Firefly\Security\Data\SecurityContextAuditorAware;
use Firefly\Security\Event\AuthenticationEventPublisher;
use Firefly\Security\Jwt\WeakSigningSecretException;
use Firefly\Security\SecurityServiceProvider;
use Firefly\Security\SecurityWiringProvider;
use Firefly\Testing\Double\RecordingAuthenticationEvents;
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

it('binds an AuthenticationEventPublisher over whatever ApplicationEventPublisher was bound before boot', function () {
    // The recording double is bound with instance() BEFORE the providers register, which is exactly how a
    // Testbench suite hands it in: FireflyServiceProvider's own DispatcherEventPublisher binding is
    // bound()-guarded, so the instance wins and the security bean wraps it.
    $events = new RecordingAuthenticationEvents;

    /** @var ApplicationContext $context */
    $context = fireflyApplication(
        config: ['firefly' => ['cqrs' => [], 'security' => ['enabled' => true]]],
        providers: [CqrsServiceProvider::class, CqrsWiringProvider::class, SecurityServiceProvider::class, SecurityWiringProvider::class],
        bindings: [ApplicationEventPublisher::class => $events],
        needs: ['cache'],
    )->make(ApplicationContext::class);

    $publisher = $context->get(AuthenticationEventPublisher::class);
    if (! $publisher instanceof AuthenticationEventPublisher) {
        throw new RuntimeException('Expected an AuthenticationEventPublisher instance.');
    }
    $publisher->publishAuthenticationSuccess(Authentication::authenticated('ada', 'ada', []));

    expect($events->successes())->toHaveCount(1)
        ->and($events->successes()[0]->authentication->getName())->toBe('ada');
});

it('does not bind an AuthenticationEventPublisher when security is disabled', function () {
    /** @var ApplicationContext $context */
    $context = bootSecurityApp(false)->make(ApplicationContext::class);

    expect($context->has(AuthenticationEventPublisher::class))->toBeFalse();
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

/*
 | A cache compiled before firefly:cache wrote proxy-plan.php lists every rule in security-methods.php while the
 | proxy plan bridged from transactional.php knows nothing about method security: a #[Service] whose rules are
 | method security alone is handed out bare, its rows compiled and enforced by nothing. `method.strict` cannot
 | see it — the manifest it checks for is present — so SecurityWiringPass refuses the boot itself, naming the
 | remedy. The guard is two file probes, so a hand-written manifest with no plan beside it is the whole fixture.
 */

/** A cache directory holding security-methods.php and nothing else — what an older firefly:cache left behind. */
function staleSecurityCache(): string
{
    $dir = sys_get_temp_dir().'/firefly-security-stale-cache-'.bin2hex(random_bytes(6));
    mkdir($dir, 0o700, true);
    (new SecurityMethodManifestCompiler)->write([], $dir.'/'.AppScan::SECURITY_METHODS);

    return $dir;
}

/**
 * @param  array<string,mixed>  $security
 */
function bootSecurityAppOverCache(string $dir, array $security): Application
{
    return fireflyApplication(
        config: ['firefly' => ['cqrs' => [], 'cache' => ['path' => $dir], 'security' => $security]],
        providers: [CqrsServiceProvider::class, CqrsWiringProvider::class, SecurityServiceProvider::class, SecurityWiringProvider::class],
        needs: ['cache'],
    );
}

it('refuses to boot over a compiled method-security manifest that has no proxy plan beside it', function () {
    $dir = staleSecurityCache();

    try {
        bootSecurityAppOverCache($dir, ['enabled' => true]);
        throw new LogicException('not refused');
    } catch (ConfigurationException $e) {
        expect($e->getMessage())->toContain($dir.'/'.AppScan::SECURITY_METHODS)
            ->and($e->getMessage())->toContain(AppScan::PROXY_PLAN)
            ->and($e->getMessage())->toContain('firefly:cache');
    }
});

it('lets the same stale cache boot when method security on beans is switched off, since the proxy link is then a pass-through by choice', function () {
    /** @var ApplicationContext $context */
    $context = bootSecurityAppOverCache(staleSecurityCache(), ['enabled' => true, 'method' => ['enabled' => false]])->make(ApplicationContext::class);

    expect($context->get(CommandAuthorizer::class))->toBeInstanceOf(SecurityCommandAuthorizer::class);
});

it('lets the same stale cache boot when the master flag is off, since nothing enforces then', function () {
    /** @var ApplicationContext $context */
    $context = bootSecurityAppOverCache(staleSecurityCache(), ['enabled' => false])->make(ApplicationContext::class);

    expect($context->get(CommandAuthorizer::class))->toBeInstanceOf(AllowAllAuthorizer::class);
});
