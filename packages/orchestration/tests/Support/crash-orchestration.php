<?php

declare(strict_types=1);

use Firefly\Orchestration\Dlq\DatabaseDeadLetterStore;
use Firefly\Orchestration\Persistence\DatabaseExecutionPersistenceProvider;
use Firefly\Orchestration\Tests\Support\CrashScenarios;
use Firefly\Orchestration\Tests\Support\IntegrationDatabase;
use Firefly\Orchestration\Tests\Support\TestEngines;

/*
 | The process CrashRecoveryTest kills: boots the engines over the shared database and starts a scenario
 | whose step calls exit(70). Reaching the end of this file means the scenario did NOT crash.
 */
require dirname(__DIR__, 4).'/vendor/autoload.php';

/** @var list<string> $arguments */
$arguments = array_values(array_map(static fn (mixed $argument): string => is_scalar($argument) ? (string) $argument : '', is_array($_SERVER['argv'] ?? null) ? $_SERVER['argv'] : []));
[, $target, $scenario, $correlationId, $marks] = $arguments + ['', '', '', '', ''];

if (str_starts_with($target, 'sqlite:')) {
    $connection = IntegrationDatabase::sqlite(substr($target, 7));
} elseif (str_starts_with($target, 'lane:')) {
    $connection = IntegrationDatabase::connection(substr($target, 5));
} else {
    fwrite(STDERR, "unknown target [{$target}]\n");
    exit(1);
}

$engines = TestEngines::create(persistence: new DatabaseExecutionPersistenceProvider($connection), deadLetters: new DatabaseDeadLetterStore($connection));
CrashScenarios::register($engines, $marks, crash: true);
CrashScenarios::start($engines, $scenario, $correlationId);

fwrite(STDERR, "the scenario did not crash\n");
exit(1);
