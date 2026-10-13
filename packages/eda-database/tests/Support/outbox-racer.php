<?php

declare(strict_types=1);

use Firefly\Eda\Database\DatabaseEventConsumer;
use Firefly\Eda\Database\Outbox\Claim\OutboxClaimStrategies;
use Firefly\Eda\Database\Outbox\OutboxRelay;
use Firefly\Eda\Database\Outbox\OutboxSchema;
use Firefly\Eda\EventPublisher;
use Firefly\Testing\Integration\IntegrationDsn;

require dirname(__DIR__, 4).'/vendor/autoload.php';

/*
 * One racer of OutboxConcurrencyIntegrationTest — a separate OS process with its own connection, exactly what two
 * workers on two nodes are. argv: lane, mode (relay|consume), strategy, go-file, claimant[, sqlite path]. Prints
 * "ready", spins until the parent creates the go-file (so both racers start together), drains the outbox printing
 * "delivered <n>" for every row it delivered, then "done". A 2 ms pause per delivery keeps both racers inside the
 * claim at the same time; without it one racer can finish before the other has started.
 */
$lane = $argv[1] ?? '';
$mode = $argv[2] ?? '';
$strategy = $argv[3] ?? 'auto';
$go = $argv[4] ?? '';
$claimant = $argv[5] ?? 'racer';
$connection = IntegrationDsn::connect($lane, 'racer', isset($argv[6]) ? ['database' => $argv[6]] : []);
$claim = OutboxClaimStrategies::configured($strategy);

$report = static function (mixed $n): void {
    fwrite(STDOUT, 'delivered '.(is_scalar($n) ? (string) $n : '?')."\n");
};

$downstream = new class($report) implements EventPublisher
{
    /** @param Closure(mixed): void $report */
    public function __construct(private readonly Closure $report) {}

    public function subscribe(string $eventTypePattern, callable $handler): void {}

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    public function publish(string $destination, string $eventType, array $payload, array $headers = []): void
    {
        usleep(2000);
        ($this->report)($payload['n'] ?? null);
    }

    public function start(): void {}

    public function stop(): void {}
};

$pending = static fn (): int => $connection->table(OutboxSchema::TABLE)->where('status', OutboxSchema::STATUS_PENDING)->count();

fwrite(STDOUT, "ready\n");
fflush(STDOUT);

$deadline = microtime(true) + 90;
while (! is_file($go) && microtime(true) < $deadline) {
    usleep(1000);
}

if ($mode === 'relay') {
    $relay = new OutboxRelay($connection, $downstream, $claim, batchSize: 5, leaseSeconds: 120, claimant: $claimant);
    while (microtime(true) < $deadline) {
        if ($relay->relayBatch() === 0 && $pending() === 0) {
            break;
        }
    }
} else {
    $consumer = new DatabaseEventConsumer($connection, $claim, leaseSeconds: 120, claimant: $claimant, awaitWork: static function (int $timeoutMs): void {
        usleep(1000);
    });
    while (microtime(true) < $deadline) {
        $received = $consumer->poll(10);
        if ($received === null) {
            if ($pending() === 0) {
                break;
            }

            continue;
        }
        usleep(2000);
        $report($received->envelope?->payload['n'] ?? null);
        $consumer->ack($received);
    }
}

fwrite(STDOUT, "done\n");
$connection->disconnect();
