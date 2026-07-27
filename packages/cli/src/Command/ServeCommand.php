<?php

declare(strict_types=1);

namespace Firefly\Cli\Command;

use Illuminate\Console\Command;
use Laravel\Octane\Octane;

/**
 * Thin passthrough to `artisan serve` (or `octane:start` when laravel/octane is installed) —
 * reimplements nothing. laravel/octane is an OPTIONAL runtime dependency: probed via class_exists()
 * only, never required by this package's composer.json.
 */
final class ServeCommand extends Command
{
    /** @var string */
    protected $signature = 'firefly:serve {--host=127.0.0.1} {--port=8000}';

    /** @var string */
    protected $description = 'Thin passthrough to artisan serve (or octane:start when laravel/octane is installed).';

    public function handle(): int
    {
        $params = ['--host' => $this->option('host'), '--port' => $this->option('port')];

        return class_exists(Octane::class)
            ? $this->call('octane:start', $params)
            : $this->call('serve', $params);
    }
}
