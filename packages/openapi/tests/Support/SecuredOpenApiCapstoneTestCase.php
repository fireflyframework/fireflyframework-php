<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Tests\Support;

use Firefly\Cqrs\CqrsServiceProvider;
use Firefly\Cqrs\CqrsWiringProvider;
use Firefly\Security\SecurityServiceProvider;
use Firefly\Security\SecurityWiringProvider;

/**
 * The openapi capstone PLUS firefly/security, so the document served over HTTP is produced with the
 * SecurityArgumentResolver in the registry the way a real application's is: SecurityWiringPass add()s it at
 * boot (with the master flag off, as here — principal injection is master-independent), and the
 * OperationFactory bean is handed that same singleton. The security package's own principal fixture is
 * scanned beside the orders one, so the paths under test are the very actions its capstone drives through
 * the dispatcher — proof that what the document leaves out is what the runtime never reads from the request.
 * The cqrs providers come along for the reason SecuredActuatorCapstoneTestCase gives: security's beans
 * type-hint cqrs' HandlerManifest.
 */
abstract class SecuredOpenApiCapstoneTestCase extends OpenApiCapstoneTestCase
{
    protected function fireflyProviders(): array
    {
        return [
            ...parent::fireflyProviders(),
            CqrsServiceProvider::class,
            CqrsWiringProvider::class,
            SecurityServiceProvider::class,
            SecurityWiringProvider::class,
        ];
    }

    protected function configOverrides(): array
    {
        return [
            ...parent::configOverrides(),
            'firefly.scan.paths' => [
                ...FixtureDocument::psr4(),
                'Firefly\\Security\\Tests\\Fixtures\\Principal\\' => dirname(__DIR__, 3).'/security/tests/Fixtures/Principal',
            ],
            'firefly.security.enabled' => false,
        ];
    }
}
