<?php

declare(strict_types=1);

use Firefly\FeatureFlags\Store\DatabaseFlagStore;
use Firefly\FeatureFlags\Store\FlagStoreConflict;
use Firefly\FeatureFlags\Tests\Support\DatabaseStoreIntegration;

require dirname(__DIR__, 4).'/vendor/autoload.php';

$connection = DatabaseStoreIntegration::connection($argv[1] ?? '', $argv[2] ?? '');
$connection->beforeExecuting(static function (string $query): void {
    if (str_contains($query, 'for update')) {
        // The store transaction is open and its locked read is about to contend with the parent process.
        fwrite(STDOUT, "reading\n");
        fflush(STDOUT);
    }
});
try {
    (new DatabaseFlagStore($connection))->put('race', ['state' => 'ENABLED', 'variants' => ['on' => true], 'defaultVariant' => 'on'], 'child', 1);
    fwrite(STDOUT, "unexpected-success\n");
    exit(1);
} catch (FlagStoreConflict) {
    fwrite(STDOUT, "conflict\n");
}
