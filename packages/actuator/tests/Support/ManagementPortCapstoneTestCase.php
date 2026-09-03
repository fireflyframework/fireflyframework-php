<?php

declare(strict_types=1);

namespace Firefly\Actuator\Tests\Support;

/**
 * The same full HTTP boot as ActuatorCapstoneTestCase, with firefly.management.server.port set.
 *
 * It has to be its OWN boot, not a config()->set() inside a test body: ManagementServerSettings is a singleton
 * #[Bean] resolved once at BootPhase::FlushDefinitions and ActuatorRouteRegistrar validates the port at
 * WiringPasses, so a post-boot mutation reaches neither — the same constraint managementEnabled()/exposureInclude()
 * already document on the parent.
 *
 * 9001 is deliberately NOT testbench's app.url port: app.url is `http://localhost` with no explicit port, so
 * ManagementServerSettings::applicationPort() answers null and the equality check correctly stands aside. A request
 * is then aimed at a port by passing an ABSOLUTE URL to the test helper — Laravel's prepareUrlForRequest() passes a
 * fully-qualified URL through untouched, and Symfony's Request::create() writes SERVER_PORT from its port component
 * exactly as a real SAPI writes it from the accepted socket.
 */
abstract class ManagementPortCapstoneTestCase extends ActuatorCapstoneTestCase
{
    public const MANAGEMENT_PORT = 9001;

    protected function configOverrides(): array
    {
        return parent::configOverrides() + [
            'firefly.management.server.port' => self::MANAGEMENT_PORT,
        ];
    }

    /** The same path, addressed on the management listener rather than the application one. */
    public function onManagementPort(string $path): string
    {
        return 'http://localhost:'.self::MANAGEMENT_PORT.$path;
    }
}
