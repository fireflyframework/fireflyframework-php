<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Console;

use Firefly\EventSourcing\Exception\EventSourcingException;
use Firefly\EventSourcing\Projection\ProjectionRunner;
use Illuminate\Console\Command;

/**
 * `php artisan firefly:eventsourcing:projections:run`: catches one projection, or every catch-up projection, up with
 * the global stream and exits; with --live it keeps following the stream (sleeping projection.poll-interval when
 * idle) until SIGINT/SIGTERM or --max-runtime seconds. Run it under a process supervisor on as many nodes as you
 * like: the lease lets one node advance each projection at a time.
 */
final class RunProjectionsCommand extends Command
{
    protected $signature = 'firefly:eventsourcing:projections:run
        {projection? : one projection; every catch-up projection when omitted}
        {--live : keep following the global stream until stopped}
        {--max-runtime= : in live mode, stop after this many seconds}';

    protected $description = 'Catch projections up with the global event stream, once or live.';

    public function handle(ProjectionRunner $runner): int
    {
        $name = $this->argument('projection');
        $name = is_string($name) && $name !== '' ? $name : null;

        if ($this->option('live') === true) {
            return $this->live($runner, $name);
        }

        try {
            $results = $name === null ? $runner->catchUpAll() : [$runner->catchUp($name)];
        } catch (EventSourcingException $refused) {
            $this->components->error($refused->getMessage());

            return self::FAILURE;
        }

        if ($results === []) {
            $this->components->info('No catch-up projections are registered.');
        }
        $failed = false;
        foreach ($results as $result) {
            $line = sprintf('%s: %s, %d event(s) handled, at position %d', $result->projection, $result->outcome, $result->handled, $result->toPosition);
            if ($result->failed()) {
                $failed = true;
                $this->components->error($line.' ('.(string) $result->error.')');
            } else {
                $this->components->info($line);
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function live(ProjectionRunner $runner, ?string $name): int
    {
        if ($name !== null) {
            $this->components->error('--live follows every catch-up projection; run it without a projection name.');

            return self::FAILURE;
        }

        $maxRuntime = $this->option('max-runtime');
        if ($maxRuntime !== null && ! is_numeric($maxRuntime)) {
            $this->components->error('--max-runtime must be a number of seconds.');

            return self::FAILURE;
        }

        if (extension_loaded('pcntl')) {
            $this->trap([SIGINT, SIGTERM], static function () use ($runner): void {
                $runner->stop();
            });
        }

        $cycles = $runner->runLive(is_numeric($maxRuntime) ? (float) $maxRuntime : null);
        $this->components->info("Stopped after {$cycles} cycle(s).");

        return self::SUCCESS;
    }
}
