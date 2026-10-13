<?php

declare(strict_types=1);

use Firefly\Webhooks\Idempotency\DatabaseWebhookIdempotencyStore;
use Firefly\Webhooks\Idempotency\WebhookSchema;
use Firefly\Webhooks\Tests\Support\WebhookDatabases;

foreach ([
    'PostgreSQL' => ['pgsql', 'FIREFLY_PG_DSN'],
    'MySQL' => ['mysql', 'FIREFLY_MYSQL_DSN'],
    'MariaDB' => ['mysql', 'FIREFLY_MARIADB_DSN'],
    'SQL Server' => ['sqlsrv', 'FIREFLY_SQLSRV_DSN'],
] as $backend => [$driver, $variable]) {
    it("claims once, case-sensitively, reclaims an expired receipt and prunes on {$backend}", function () use ($driver, $variable): void {
        $connection = WebhookDatabases::connection($driver, $variable);
        WebhookSchema::drop($connection->getSchemaBuilder());
        WebhookSchema::create($connection->getSchemaBuilder());
        WebhookSchema::create($connection->getSchemaBuilder());
        $now = 10_000;
        $store = new DatabaseWebhookIdempotencyStore($connection, static function () use (&$now): int {
            return $now;
        });

        try {
            expect($store->claim('stripe', 'evt_Abc', 'e-1', 60))->toBeTrue()
                ->and($store->claim('stripe', 'evt_Abc', 'e-2', 60))->toBeFalse()
                ->and($store->claim('stripe', 'evt_abc', 'e-3', 60))->toBeTrue()
                ->and($store->claim('github', 'evt_Abc', 'e-4', 60))->toBeTrue();

            $now = 10_061;
            expect($store->claim('stripe', 'evt_Abc', 'e-5', 60))->toBeTrue()
                ->and($connection->table(WebhookSchema::TABLE)->where('provider', 'stripe')->where('idempotency_key', 'evt_Abc')->value('event_id'))->toBe('e-5')
                ->and($store->prune())->toBe(2);

            $store->release('stripe', 'evt_Abc');
            expect($store->claim('stripe', 'evt_Abc', 'e-6', 60))->toBeTrue();
        } finally {
            WebhookSchema::drop($connection->getSchemaBuilder());
        }
    })->group('integration')->skip(getenv($variable) === false, "Set {$variable} to an isolated database.");

    it("answers a duplicate inside an open transaction without aborting it on {$backend}", function () use ($driver, $variable): void {
        $connection = WebhookDatabases::connection($driver, $variable);
        WebhookSchema::drop($connection->getSchemaBuilder());
        WebhookSchema::create($connection->getSchemaBuilder());
        $store = new DatabaseWebhookIdempotencyStore($connection);

        try {
            $store->claim('p', 'k', 'e-1', 60);
            $connection->beginTransaction();
            expect($store->claim('p', 'k', 'e-2', 60))->toBeFalse()
                ->and($connection->transactionLevel())->toBe(1)
                ->and($store->claim('p', 'k2', 'e-3', 60))->toBeTrue();
            $connection->commit();

            expect($connection->table(WebhookSchema::TABLE)->where('idempotency_key', 'k2')->exists())->toBeTrue();
        } finally {
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack(0);
            }
            WebhookSchema::drop($connection->getSchemaBuilder());
        }
    })->group('integration')->skip(getenv($variable) === false, "Set {$variable} to an isolated database.");
}
