<?php

declare(strict_types=1);

namespace Firefly\Actuator\Tests\Support;

/**
 * A console-shaped boot for firefly:management:serve. No management port is set here — each case sets what it needs
 * through --port, or asserts the unconfigured failure — so this base only exists to give the command an application
 * whose actuator wiring is real (the command resolves Config and ExposureModel out of the container).
 */
abstract class ManagementServeCapstoneTestCase extends ActuatorCapstoneTestCase {}
