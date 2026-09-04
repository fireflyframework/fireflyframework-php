<?php

declare(strict_types=1);

namespace Firefly\Actuator\Server;

use Firefly\Actuator\Endpoint\ExposureModel;
use Firefly\Config\Config;
use Firefly\Kernel\Exception\Framework\ConfigurationException;

/**
 * Spring's `management.server.*` — the management surface's OWN port, bind address and path prefix, read once at
 * BootPhase::FlushDefinitions into an immutable value object (the ExposureModel/OpenApiProperties lifetime, and for
 * the same reason: ActuatorRouteRegistrar mounts the routes from `mountPath()` at BootPhase::WiringPasses, so a
 * post-boot `config()->set()` could not move an already-mounted route anyway).
 *
 * WHAT PHP CAN AND CANNOT DO HERE — read this before "finishing" the feature.
 *
 * Spring Boot's `management.server.port` opens a SECOND Tomcat connector inside the SAME JVM. A PHP-FPM worker, an
 * `artisan serve` process, an Octane worker — each is handed ONE already-accepted connection by a listener it does
 * not own and never sees. There is no point in the request lifecycle at which framework code could bind a second
 * socket, and a `stream_socket_server()` opened from a request would die with the request. So this package does NOT
 * pretend to serve two ports from one process. It splits the job in three, and each third is honest about which
 * half of the guarantee it provides:
 *
 *  1. THE SECOND LISTENER is the deployment's job — a second PHP-FPM pool with its own `listen`, a second container,
 *     or a reverse-proxy rule. That is the ONLY thing that can make the management port a real network boundary,
 *     and no amount of PHP can substitute for it. See the package README's "Separate management port" section.
 *  2. THE GUARD (ManagementPortGuard) is this package's job. It compares the port the request actually ARRIVED on to
 *     the configured one and 404s the actuator otherwise, so the separation is ENFORCED in-process even when the
 *     operator's proxy rule is missing or wrong. Without it, "management.server.port" would be pure documentation:
 *     the routes are mounted on the one Router this process has, and the application port would keep serving them.
 *  3. THE DEV LISTENER is `php artisan firefly:management:serve` — a second `artisan serve` bound to the management
 *     address/port, so the feature works out of the box locally without a pool or a proxy.
 *
 * `address` is deliberately NOT part of the guard. A bind address is invisible to an HTTP request: the only thing a
 * request carries that resembles one is the Host header, which the CLIENT writes. Refusing traffic because
 * `Host: 10.0.0.4` does not equal a configured `127.0.0.1` would reject legitimate requests and accept forged ones
 * — worse than nothing. The address is a BIND directive, consumed by `firefly:management:serve` and copied into the
 * FPM pool's `listen`; the kernel enforces it long before PHP is reached.
 */
final readonly class ManagementServerSettings
{
    /**
     * @param  ?int  $port  firefly.management.server.port — null means "same port as the application", Spring's own
     *                      default, and in that state every behaviour in this package is byte-for-byte what it was
     *                      before the setting existed.
     * @param  ?string  $address  firefly.management.server.address — a BIND address, never a request-time check.
     * @param  string  $basePath  firefly.management.server.base-path, slash-trimmed; '' when unset.
     */
    public function __construct(
        public ?int $port,
        public ?string $address,
        public string $basePath,
    ) {}

    public static function fromConfig(Config $config): self
    {
        return new self(
            port: self::port($config),
            address: self::address($config),
            basePath: self::basePath($config),
        );
    }

    /** Whether an operator has asked for the management surface to live on a port of its own. */
    public function isSeparate(): bool
    {
        return $this->port !== null;
    }

    /**
     * The router path the actuator is mounted at: this prefix, then the exposure model's own base path. COMPOSES
     * with ExposureModel rather than replacing it — `firefly.management.endpoints.web.base-path` keeps meaning
     * exactly what it meant (the actuator's path), and `firefly.management.server.base-path` adds a prefix in front
     * of it, so `/manage` + `/actuator` serves `/manage/actuator/health`.
     *
     * DIVERGENCE FROM SPRING, DELIBERATE: Spring applies `management.server.base-path` ONLY when the management port
     * differs from the application port, because there the prefix is the second connector's servlet context path and
     * there is no second connector to hang it off otherwise. Applying it conditionally here would mean the actuator
     * answers at `/actuator` in development (no management port) and `/manage/actuator` in production (management
     * port set) from ONE config file — an environment-dependent URL, which is precisely the kind of "works on my
     * machine" difference this package exists to remove. PHP has no second servlet context for the prefix to belong
     * to, so there is nothing to be faithful to; the prefix is simply always part of the path.
     */
    public function mountPath(ExposureModel $exposure): string
    {
        return $this->basePath === '' ? $exposure->basePath : $this->basePath.'/'.$exposure->basePath;
    }

    /**
     * Boot-time validation: a management port EQUAL to the application port is rejected, loudly.
     *
     * Spring treats the two being equal as "serve management on the main server" — a legal way to say "no
     * separation". Here it cannot mean that, and reading it that way would be a trap. The whole mechanism is the
     * ManagementPortGuard, and a guard configured with the application's own port permits every request that reaches
     * it: an operator who wrote `management.server.port` got a config file that LOOKS isolated, a `/actuator` still
     * answering on the public port, and no signal whatsoever that the isolation they asked for is not there. That is
     * a security-relevant silent no-op, so it fails the boot instead.
     *
     * $applicationPort is whatever applicationPort() could establish; null means "unknown", and an unknown
     * application port is NOT an error — see that method for why PHP frequently cannot know it.
     */
    public function assertDistinctFrom(?int $applicationPort): void
    {
        if ($this->port === null || $applicationPort === null || $this->port !== $applicationPort) {
            return;
        }

        throw new ConfigurationException(sprintf(
            'firefly.management.server.port (%d) is the application port. A management port only isolates the '
            .'actuator when it is a DIFFERENT port served by a different listener (a second PHP-FPM pool, a second '
            .'container, or a proxy rule) — set it to a port of its own, or remove it to serve the actuator on the '
            .'application port as before.',
            $this->port,
        ));
    }

    /**
     * The port the APPLICATION is served on, or null when this process cannot know it.
     *
     * PHP is not told. An FPM pool's `listen` lives in a file the framework never reads; `artisan serve --port` is a
     * flag on a different process; behind a proxy the public port and the upstream port are different numbers on
     * different machines. So there are exactly two honest sources, in order:
     *
     *  - `firefly.server.port` — Spring's `server.port`, an explicit declaration. Nothing in the framework binds it
     *    (nothing could); it exists so an operator can TELL the framework what the deployment does, and get the
     *    equality check above in return.
     *  - the explicit port in `app.url` — the local case where this mistake actually happens
     *    (`APP_URL=http://localhost:8000` next to `management.server.port=8000`). Only an EXPLICIT port counts:
     *    `https://api.example.test` yields null rather than a guessed 443, because a public URL behind a proxy says
     *    nothing about the port this process's listener accepted on, and inventing one would fail boots that are
     *    correctly configured.
     *
     * Returning null when neither is available is the right answer, not a gap to be papered over: refusing to guess
     * is what keeps this check from being the thing that breaks a valid deployment.
     */
    public static function applicationPort(Config $config): ?int
    {
        $declared = self::asPort($config->get('firefly.server.port'));
        if ($declared !== null) {
            return $declared;
        }

        $url = $config->get('app.url');
        if (! is_string($url) || $url === '') {
            return null;
        }

        $port = parse_url($url, PHP_URL_PORT);

        return is_int($port) ? $port : null;
    }

    /**
     * An UNSET port is `null` OR `''`, not just a missing key. `'port' => env('FIREFLY_MANAGEMENT_PORT')` is the
     * spelling every published Laravel config file uses, and env() answers null (or '' for an empty variable) when
     * the variable is absent — while Illuminate's `Repository::has()` reports true for a key explicitly set to null.
     * Reading this through Config::int() would therefore throw "Required configuration key is not set" for the most
     * ordinary possible config file, so the raw value is normalised here instead.
     */
    private static function port(Config $config): ?int
    {
        $raw = $config->get('firefly.management.server.port');
        if ($raw === null || $raw === '') {
            return null;
        }

        $port = self::asPort($raw);
        if ($port === null) {
            throw new ConfigurationException(sprintf(
                'firefly.management.server.port must be a TCP port between 1 and 65535, got [%s].',
                is_scalar($raw) ? (string) $raw : get_debug_type($raw),
            ));
        }

        return $port;
    }

    /** null for anything that is not an in-range TCP port, so both callers can decide what that means. */
    private static function asPort(mixed $raw): ?int
    {
        $port = match (true) {
            is_int($raw) => $raw,
            is_string($raw) && preg_match('/^\d+$/', trim($raw)) === 1 => (int) trim($raw),
            default => null,
        };

        return $port !== null && $port >= 1 && $port <= 65535 ? $port : null;
    }

    private static function address(Config $config): ?string
    {
        $raw = $config->get('firefly.management.server.address');

        return is_string($raw) && trim($raw) !== '' ? trim($raw) : null;
    }

    private static function basePath(Config $config): string
    {
        $raw = $config->get('firefly.management.server.base-path');

        return is_string($raw) ? trim(trim($raw), '/') : '';
    }
}
