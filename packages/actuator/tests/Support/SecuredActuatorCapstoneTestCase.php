<?php

declare(strict_types=1);

namespace Firefly\Actuator\Tests\Support;

use Firefly\Cqrs\CqrsServiceProvider;
use Firefly\Cqrs\CqrsWiringProvider;
use Firefly\Security\SecurityServiceProvider;
use Firefly\Security\SecurityWiringProvider;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;

/**
 * The actuator capstone PLUS firefly/security, with the recommended lockdown: /actuator/health + /actuator/info are
 * permitAll, every other /actuator/** requires ROLE_ACTUATOR. Securing actuator is PURE CONFIG — HttpSecurityFilter
 * already glob-matches /actuator/**, so there is NO code edge Actuator→Security (§7 risk 5).
 */
abstract class SecuredActuatorCapstoneTestCase extends ActuatorCapstoneTestCase
{
    /**
     * @param  Application  $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        // NOTE (brief-test fix): the brief's draft registered only the Security providers. Once
        // firefly.security.enabled=true, SecurityAutoConfiguration eagerly resolves commandAuthorizer() ->
        // MethodSecurityMessageEnforcer, which type-hints HandlerManifest directly (a firefly/cqrs class with no
        // default binding of its own). Without CqrsWiringProvider registered too, the container tries to
        // autowire HandlerManifest via reflection and fails (its constructor takes plain arrays, no defaults) —
        // BindingResolutionException at boot. firefly/security's own RealProviderBootTest (bootSecurityAppWith)
        // always pairs Security with Cqrs providers for exactly this reason; mirrored here.
        return [
            ...parent::getPackageProviders($app),
            CqrsServiceProvider::class,
            CqrsWiringProvider::class,
            SecurityServiceProvider::class,
            SecurityWiringProvider::class,
        ];
    }

    /**
     * @param  Application  $app
     */
    protected function resolveApplicationConfiguration($app): void
    {
        parent::resolveApplicationConfiguration($app);

        /** @var Repository $config */
        $config = $app->make('config');
        $config->set('firefly.management.endpoints.web.exposure.include', 'health,info,env');
        $config->set('firefly.security.enabled', true);
        $config->set('firefly.security.http.enabled', true);
        $config->set('firefly.security.http.rules', [
            ['pattern' => 'actuator/health', 'access' => 'permitAll'],
            ['pattern' => 'actuator/info', 'access' => 'permitAll'],
            ['pattern' => 'actuator/*', 'access' => 'hasRole:ACTUATOR'],
        ]);
    }
}
