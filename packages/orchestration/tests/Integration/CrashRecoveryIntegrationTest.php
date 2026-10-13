<?php

declare(strict_types=1);

use Firefly\Orchestration\Core\ExecutionStatus;
use Firefly\Orchestration\Dlq\DatabaseDeadLetterStore;
use Firefly\Orchestration\Persistence\DatabaseExecutionPersistenceProvider;
use Firefly\Orchestration\Tests\Support\CrashScenarios;
use Firefly\Orchestration\Tests\Support\IntegrationDatabase;
use Firefly\Orchestration\Tests\Support\TestEngines;

foreach (IntegrationDatabase::servers() as $server => [$driver, $variable, $extension]) {
    foreach (['resume' => ['reserve', 'charge'], 'compensate' => ['reserve', 'release']] as $action => $expected) {
        it("recovers a saga killed mid-step on {$server} with action {$action}", function () use ($driver, $variable, $action, $expected): void {
            $connection = IntegrationDatabase::fresh(IntegrationDatabase::connection($driver, $variable));
            $marks = sys_get_temp_dir().'/firefly-orchestration-crash-'.bin2hex(random_bytes(6)).'.marks';
            $correlationId = "crash-{$action}-".bin2hex(random_bytes(4));

            try {
                expect(CrashScenarios::crash("{$driver}|{$variable}", 'saga', $correlationId, $marks))->toBe(70);

                $engines = TestEngines::create(['recovery' => ['action' => $action, 'stale-threshold' => '1m']], new DatabaseExecutionPersistenceProvider($connection), new DatabaseDeadLetterStore($connection));
                CrashScenarios::register($engines, $marks, crash: false);
                $engines->clock->advance(120.0);
                $engines->recovery->recover();

                expect(CrashScenarios::marks($marks))->toBe($expected)
                    ->and($engines->persistence->findById($correlationId)?->status)->toBe($action === 'resume' ? ExecutionStatus::Completed : ExecutionStatus::Cancelled);
            } finally {
                $connection->disconnect();
            }
        })->group('integration')->skip(IntegrationDatabase::skipReason($variable, $extension) !== null, (string) IntegrationDatabase::skipReason($variable, $extension));
    }
}
