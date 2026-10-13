<?php

declare(strict_types=1);

namespace Firefly\Eda\Postgres\Tests\Support;

/**
 * The provider=postgres capstone configured through the NEW firefly.eda.database.* keys only — the proof that the
 * Postgres provider reads the shared settings (and so that an app moving between the two providers keeps its config).
 */
abstract class PostgresSettingsCapstoneTestCase extends OutboxCapstoneTestCase
{
    /** @return array<string, mixed> */
    protected function configOverrides(): array
    {
        return [
            ...parent::configOverrides(),
            'firefly.eda.database.max-attempts' => 1,
            'firefly.eda.database.channel' => 'orders_channel',
        ];
    }
}
