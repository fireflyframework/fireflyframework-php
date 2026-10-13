<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Tests\Support;

use Firefly\Testing\Integration\IntegrationDsn;
use Illuminate\Database\Connection;
use RuntimeException;

final class DatabaseStoreIntegration
{
    public static function connection(string $driver, string $variable): Connection
    {
        if (IntegrationDsn::missing($variable)) {
            throw new RuntimeException('An isolated integration DSN is required.');
        }

        return IntegrationDsn::connectFor($driver, $variable);
    }
}
