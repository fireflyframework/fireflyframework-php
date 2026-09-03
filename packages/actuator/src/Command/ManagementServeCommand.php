<?php

declare(strict_types=1);

namespace Firefly\Actuator\Command;

use Firefly\Actuator\Endpoint\ExposureModel;
use Firefly\Actuator\Server\ManagementServerSettings;
use Firefly\Config\Config;
use Illuminate\Console\Command;

/**
 * `php artisan firefly:management:serve` — the SECOND LISTENER, for development.
 *
 * `firefly.management.server.port` is only half a mechanism on its own. ManagementPortGuard makes the actuator
 * refuse the application port, which is the enforcement half; something still has to ANSWER on the management port,
 * and in production that is a second PHP-FPM pool, a second container, or a proxy rule (see the package README).
 * None of those exist on a laptop, so without this command the honest result of setting a management port locally
 * would be an actuator that 404s everywhere and a developer concluding the feature is broken. This command is what
 * makes "it works out of the box" true in development: it starts a second `artisan serve` bound to the configured
 * management address and port, alongside whichever server is already serving the application.
 *
 * IT DELEGATES TO `serve`, NOT TO `octane:start`, unlike firefly/cli's own `firefly:serve`. The management listener
 * is a low-traffic side channel whose entire job is to exist; booting a second Octane supervisor (with its own
 * worker pool, state resetters and reload watcher) to serve `/actuator/health` would cost more than the application
 * server it sits next to. The application's own runtime choice is untouched — this is a sibling process, not a
 * replacement.
 *
 * WHAT THIS DOES NOT DO, and the README says so too: it does not keep APPLICATION routes off the management port.
 * One `artisan serve` is one Laravel application; every route it has is reachable on the port it was given. The
 * guarantee this feature actually provides is one-directional — the ACTUATOR is unreachable on the application
 * port — and restricting the reverse direction is a listener-level concern (an FPM pool that only nginx's
 * management server block talks to, a container the public ingress has no route to), not something PHP can do from
 * inside a request it has already been handed.
 */
final class ManagementServeCommand extends Command
{
    /** @var string */
    protected $signature = 'firefly:management:serve
        {--host= : Bind address; defaults to firefly.management.server.address, then 127.0.0.1.}
        {--port= : Listen port; defaults to firefly.management.server.port.}';

    /** @var string */
    protected $description = 'Run a second dev listener for the actuator on the management port (firefly.management.server.port).';

    public function handle(Config $config, ExposureModel $exposure): int
    {
        $settings = ManagementServerSettings::fromConfig($config);

        // A malformed --port must NOT quietly fall back to the configured one: the operator typed a port because
        // they meant that port, and starting a listener somewhere else is the kind of "it ran, so it worked"
        // outcome that gets noticed only when the health check they were debugging still fails.
        $typed = $this->stringOption('port');
        if ($typed !== null && self::asPort($typed) === null) {
            $this->components->error("[{$typed}] is not a TCP port between 1 and 65535.");

            return self::FAILURE;
        }

        $port = $typed !== null ? self::asPort($typed) : $settings->port;
        if ($port === null) {
            $this->components->error(
                'No management port is configured. Set firefly.management.server.port (or pass --port) — without '
                .'one the actuator is served on the application port and this command has nothing to bind.',
            );

            return self::FAILURE;
        }

        // The same fail-fast ActuatorRouteRegistrar applies at boot, repeated here because --port bypasses config
        // entirely: a management listener on the application's own port is not a second listener, it is a port
        // conflict that would either refuse to bind or shadow the application.
        $applicationPort = ManagementServerSettings::applicationPort($config);
        if ($applicationPort === $port) {
            $this->components->error(sprintf(
                'Port %d is the application port. The management listener must have a port of its own.',
                $port,
            ));

            return self::FAILURE;
        }

        $host = $this->stringOption('host') ?? $settings->address ?? '127.0.0.1';

        $this->report($host, $port, $settings->mountPath($exposure));

        return $this->call('serve', ['--host' => $host, '--port' => (string) $port]);
    }

    /**
     * `0.0.0.0`/`::` are wildcard BIND addresses, not addresses a browser can open — the same distinction
     * firefly/cli's ServeCommand draws, and for the same reason: the bind argument is passed through exactly as
     * typed, only the printed link is rewritten into something clickable.
     */
    private function report(string $host, int $port, string $mountPath): void
    {
        $printable = match ($host) {
            '0.0.0.0', '::', '[::]' => '127.0.0.1',
            default => $host,
        };

        $this->newLine();
        $this->line('  <fg=gray>Actuator</> <options=bold>http://'.$printable.':'.$port.'/'.$mountPath.'</>');
        $this->line('  <fg=gray>Bind    </> '.$host.':'.$port);
        $this->line('  <fg=gray>Note    </> the actuator answers ONLY here; application routes still answer on both');
        $this->line('  <fg=gray>        </> ports, because one PHP process is one application. In production give');
        $this->line('  <fg=gray>        </> this port its own PHP-FPM pool, container or proxy rule.');
        $this->newLine();
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** null for anything that is not an in-range TCP port — the same rule ManagementServerSettings applies to config. */
    private static function asPort(string $value): ?int
    {
        if (preg_match('/^\d+$/', $value) !== 1) {
            return null;
        }

        $port = (int) $value;

        return $port >= 1 && $port <= 65535 ? $port : null;
    }
}
