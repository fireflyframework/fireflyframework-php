<?php

declare(strict_types=1);

use Firefly\Data\Exception\PersistenceExceptionTranslator;
use Firefly\Kernel\Exception\Infrastructure\DeadlockLoserDataAccessException;
use Firefly\Testing\Integration\IntegrationDsn;

require dirname(__DIR__, 4).'/vendor/autoload.php';

/*
 * The other half of a deliberate deadlock: this process holds row 2 and then asks for row 1 while the test process
 * holds row 1 and asks for row 2. The server kills one of the two; whichever it is must see the failure translated
 * into DeadlockLoserDataAccessException.
 */
$lane = $argv[1] ?? '';
$go = $argv[2] ?? '';
$connection = IntegrationDsn::connect($lane, 'deadlock-racer');

$connection->beginTransaction();
$connection->table('firefly_deadlock_probe')->where('id', 2)->update(['name' => 'racer']);
fwrite(STDOUT, "locked\n");
fflush(STDOUT);

$deadline = microtime(true) + 20;
while (! is_file($go) && microtime(true) < $deadline) {
    usleep(1000);
}

try {
    $connection->table('firefly_deadlock_probe')->where('id', 1)->update(['name' => 'racer']);
    $connection->commit();
    fwrite(STDOUT, "committed\n");
} catch (Throwable $e) {
    $connection->rollBack();
    $translated = (new PersistenceExceptionTranslator)->translate($e, $connection->getDriverName());
    fwrite(STDOUT, ($translated instanceof DeadlockLoserDataAccessException ? 'deadlock' : 'other '.$translated::class)."\n");
}

$connection->disconnect();
