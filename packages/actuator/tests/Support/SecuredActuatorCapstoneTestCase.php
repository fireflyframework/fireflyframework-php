<?php

declare(strict_types=1);

namespace Firefly\Actuator\Tests\Support;

use Firefly\Cqrs\CqrsServiceProvider;
use Firefly\Cqrs\CqrsWiringProvider;
use Firefly\Security\SecurityServiceProvider;
use Firefly\Security\SecurityWiringProvider;
use Illuminate\Support\ServiceProvider;

/**
 * The actuator capstone PLUS firefly/security, with the recommended lockdown: /actuator/health + /actuator/info are
 * permitAll, every other /actuator/** requires ROLE_ACTUATOR. Securing actuator is PURE CONFIG — HttpSecurityFilter
 * already glob-matches /actuator/**, so there is NO code edge Actuator→Security (§7 risk 5).
 */
abstract class SecuredActuatorCapstoneTestCase extends ActuatorCapstoneTestCase
{
    /**
     * @return list<class-string<ServiceProvider>>
     */
    protected function fireflyProviders(): array
    {
        // NOTE (brief-test fix): the brief's draft registered only the Security providers. Once
        // firefly.security.enabled=true, SecurityAutoConfiguration eagerly resolves commandAuthorizer() ->
        // MethodSecurityMessageEnforcer, which type-hints HandlerManifest directly (a firefly/cqrs class with no
        // default binding of its own). Without CqrsWiringProvider registered too, the container tries to
        // autowire HandlerManifest via reflection and fails (its constructor takes plain arrays, no defaults) —
        // BindingResolutionException at boot. firefly/security's own RealProviderBootTest (bootSecurityAppWith)
        // always pairs Security with Cqrs providers for exactly this reason; mirrored here. MERGES with the
        // parent's own 4 actuator providers so both boots' worth of wiring are present.
        return [
            ...parent::fireflyProviders(),
            CqrsServiceProvider::class,
            CqrsWiringProvider::class,
            SecurityServiceProvider::class,
            SecurityWiringProvider::class,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function configOverrides(): array
    {
        // MERGES with the parent's management/exposure keys (spread first) so the secured boot keeps
        // managementEnabled()/the base health.db flag; only the exposure list + security keys are added/overridden.
        return [
            ...parent::configOverrides(),
            'firefly.management.endpoints.web.exposure.include' => 'health,info,env',
            'firefly.security.enabled' => true,
            'firefly.security.http.enabled' => true,
            'firefly.security.http.rules' => [
                ['pattern' => 'actuator/health', 'access' => 'permitAll'],
                ['pattern' => 'actuator/info', 'access' => 'permitAll'],
                ['pattern' => 'actuator/*', 'access' => 'hasRole:ACTUATOR'],
            ],
        ];
    }
}
