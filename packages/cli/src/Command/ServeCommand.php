<?php

declare(strict_types=1);

namespace Firefly\Cli\Command;

use Firefly\Context\Scan\AppScan;
use Illuminate\Console\Command;
use Laravel\Octane\Octane;

/**
 * Thin passthrough to `artisan serve` (or `octane:start` when laravel/octane is installed) —
 * reimplements nothing. laravel/octane is an OPTIONAL runtime dependency: probed via class_exists()
 * only, never required by this package's composer.json.
 *
 * Before delegating it prints the URL and the BOOT MODE. The boot mode is the single most useful fact
 * about a running LaraFly app and it was previously invisible: an app whose manifests are compiled reads
 * routes, handlers, listeners, scheduled tasks and method-security rules straight off bootstrap/cache/
 * firefly with zero reflection, whereas an app without them re-scans firefly.scan.paths on every boot.
 * The two behave identically until they do not — a stale compiled artifact serves the routes you compiled,
 * not the ones you just wrote — and "why is my new #[GetMapping] 404ing" is exactly the question this line
 * answers. AppScan::cachedFile() is the same probe the framework itself uses to choose between the two
 * (see Firefly\Context\Scan\AppScan), so the report can never disagree with the boot it describes;
 * firefly/cli already requires firefly/context, so reading it costs no new dependency.
 */
final class ServeCommand extends Command
{
    /** @var string */
    protected $signature = 'firefly:serve {--host=127.0.0.1} {--port=8000}';

    /** @var string */
    protected $description = 'Thin passthrough to artisan serve (or octane:start when laravel/octane is installed), reporting the URL and whether the app booted compiled or scanned.';

    public function handle(): int
    {
        $host = $this->stringOption('host', '127.0.0.1');
        $port = $this->stringOption('port', '8000');
        $octane = class_exists(Octane::class);

        $this->report($host, $port, $octane);

        $params = ['--host' => $host, '--port' => $port];

        return $octane
            ? $this->call('octane:start', $params)
            : $this->call('serve', $params);
    }

    private function report(string $host, string $port, bool $octane): void
    {
        $compiled = AppScan::cachedFile($this->laravel, AppScan::ROUTES);

        $this->newLine();
        $this->line('  <fg=gray>URL     </> <options=bold>http://'.$this->reachableHost($host).':'.$port.'</>');
        $this->line('  <fg=gray>Runtime </> '.($octane ? 'Octane (octane:start)' : 'PHP dev server (artisan serve)'));
        $this->line('  <fg=gray>Boot    </> '.($compiled !== null
            ? '<fg=green>compiled</> — '.$this->relative($compiled)
            : '<fg=yellow>scanned</> — no compiled manifests; run `php artisan firefly:cache` to compile'));
        $this->newLine();
    }

    /**
     * The host to PRINT, which is not always the host to BIND. `--host=0.0.0.0` (or `::`) is the usual way
     * to expose the dev server to a container host or a phone on the LAN, but those are wildcard bind
     * addresses: pasting http://0.0.0.0:8000 into a browser is a coin flip across platforms. The bind
     * address passed to serve/octane is left exactly as the user typed it; only the printed link is
     * rewritten to something a browser will actually open.
     */
    private function reachableHost(string $host): string
    {
        return match ($host) {
            '0.0.0.0', '::', '[::]' => '127.0.0.1',
            default => $host,
        };
    }

    /**
     * Trim the application base path off an absolute artifact path so the line stays readable in a narrow
     * terminal. Falls back to the absolute path when the file lives outside the project (a configured
     * `firefly.cache.path` may).
     */
    private function relative(string $path): string
    {
        $base = rtrim($this->laravel->basePath(), '/').'/';

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }

    private function stringOption(string $name, string $fallback): string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : $fallback;
    }
}
