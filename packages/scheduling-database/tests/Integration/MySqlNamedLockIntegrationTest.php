<?php

declare(strict_types=1);

use Firefly\Scheduling\Database\MySqlNamedLock;
use Firefly\Testing\FireflyDatabaseTestCase;
use Firefly\Testing\Integration\IntegrationDsn;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;

uses(FireflyDatabaseTestCase::class);

/** GET_LOCK across two sessions on both MySQL-family servers: exclusion, release, release-on-disconnect, long names. */
foreach (['mysql', 'mariadb'] as $lane) {
    it("excludes across sessions and frees on release or disconnect on {$lane}", function () use ($lane): void {
        config([
            'database.connections.session_a' => IntegrationDsn::connectionConfig($lane),
            'database.connections.session_b' => IntegrationDsn::connectionConfig($lane),
        ]);
        /** @var ConnectionResolverInterface $connections */
        $connections = App::make(ConnectionResolverInterface::class);
        $a = new MySqlNamedLock($connections, 'session_a');
        $b = new MySqlNamedLock($connections, 'session_b');
        $name = 'firefly:test:'.bin2hex(random_bytes(4));
        $long = $name.':'.str_repeat('LongJobName', 10);

        expect($a->tryAcquire($name, 30))->toBeTrue()
            ->and($b->tryAcquire($name, 30))->toBeFalse()
            ->and($a->tryAcquire($long, 30))->toBeTrue()
            ->and($b->tryAcquire($long, 30))->toBeFalse();

        $a->release($name);
        expect($b->tryAcquire($name, 30))->toBeTrue();
        $b->release($name);

        // The session ends: the server frees every named lock it held (as soon as it notices the closed socket).
        DB::connection('session_a')->disconnect();
        DB::purge('session_a');
        $freed = false;
        $deadline = microtime(true) + 3;
        while (! $freed && microtime(true) < $deadline) {
            $freed = $b->tryAcquire($long, 30);
            if (! $freed) {
                usleep(100_000);
            }
        }
        expect($freed)->toBeTrue();
        $b->release($long);
        DB::connection('session_b')->disconnect();
    })->group('integration')->skip(IntegrationDsn::skipReason($lane) !== null, (string) IntegrationDsn::skipReason($lane));
}
