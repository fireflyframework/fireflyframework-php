<?php

declare(strict_types=1);

namespace Firefly\Actuator\Health;

/**
 * The shipped default: nobody may read health details. It is what `when-authorized` has always degraded to
 * in an application with no code able to say who asked, and it is the only safe answer — health details name
 * database connections, disk paths, broker hosts and version strings, and a surface that leaks them to an
 * unauthenticated caller is a reconnaissance gift. Replaced by firefly/security's authorizer the moment the
 * security master flag is on.
 */
final class DenyHealthDetailsAuthorizer implements HealthDetailsAuthorizer
{
    public function mayReadDetails(): bool
    {
        return false;
    }
}
