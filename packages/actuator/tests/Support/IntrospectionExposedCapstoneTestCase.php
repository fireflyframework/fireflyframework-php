<?php

declare(strict_types=1);

namespace Firefly\Actuator\Tests\Support;

/**
 * The "configprops + caches explicitly exposed" sibling of ActuatorCapstoneTestCase — the same own-boot
 * requirement ActuatorEnvExposedCapstoneTestCase documents: ExposureModel's include list is a singleton #[Bean]
 * captured once at BootPhase::FlushDefinitions, so an endpoint cannot be exposed from inside a test body.
 *
 * `firefly.scan.paths` points at this package's own tests/Fixtures directory rather than a hand-bound
 * ConfigPropertiesManifest, so the ENTIRE production chain runs for real: FireflyAutoConfigureServiceProvider
 * scans the roots, FlushDefinitionsPass hands the result to ConfigRegistrar, ConfigRegistrar binds (or, for
 * the #[Profile('prod')] fixture, refuses to bind) each DTO, and ConfigPropsEndpoint independently resolves
 * the same manifest through AppScan's cached-then-scanned convention. Binding a manifest directly would have
 * skipped both halves and proved only that the renderer can format an array it was handed.
 */
class IntrospectionExposedCapstoneTestCase extends ActuatorCapstoneTestCase
{
    protected function exposureInclude(): string
    {
        return 'health,info,configprops,caches';
    }

    /**
     * @return array<string, mixed>
     */
    protected function configOverrides(): array
    {
        return parent::configOverrides() + [
            'firefly.scan.paths' => ['Firefly\\Actuator\\Tests\\Fixtures\\' => dirname(__DIR__).'/Fixtures'],
            'demo' => [
                'name' => 'checkout',
                // A string where DemoProperties declares int, and snake_case keys where it declares camelCase
                // parameters: between them they prove /configprops reports the value AFTER relaxed binding and
                // coercion, which is the whole reason it reads the bound instance instead of the config tree.
                'retries' => '3',
                'api_token' => 'super-secret-token',
                'signing_keys' => ['active' => 'PRIVATE-A'],
                'endpoint' => ['url' => 'https://demo.test', 'password' => 'hunter2'],
            ],
            'cache.default' => 'array',
            'cache.stores' => [
                'array' => ['driver' => 'array', 'serialize' => false],
                'redis' => ['driver' => 'redis', 'connection' => 'cache'],
            ],
        ];
    }
}
