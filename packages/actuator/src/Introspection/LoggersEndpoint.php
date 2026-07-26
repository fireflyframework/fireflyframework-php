<?php

declare(strict_types=1);

namespace Firefly\Actuator\Introspection;

use Firefly\Actuator\Endpoint\ActuatorEndpoint;
use Firefly\Actuator\Endpoint\EndpointRequest;
use Firefly\Actuator\Endpoint\EndpointResponse;
use Firefly\Container\Attributes\Component;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Log\Logger as LaravelLogger;
use Illuminate\Log\LogManager;
use Monolog\Handler\AbstractHandler;
use Monolog\Level;
use Monolog\Logger as Monolog;

/**
 * GET /loggers → the configured level of every logging channel. POST /loggers/{name} with body
 * {"level":"debug"} → sets the live Monolog handler levels for that channel at runtime (Laravel/
 * Monolog reuse). A malformed request or unknown level returns null (→ 404 via the dispatch action).
 *
 * Monolog-3 API note (verified against the installed monolog/monolog 3.10.0): unlike an earlier
 * sketch of this endpoint, neither `Monolog\Logger::toMonologLevel()` nor `Level::fromName()` can be
 * called with a bare user-supplied `string` under PHPStan (level max): both are typed, via
 * `@phpstan-param`, to a UNION of Level::VALUES/Level::NAMES string-and-int LITERALS (plus the Level
 * enum and Psr\Log\LogLevel::* constants) — not plain `string` — because both are meant to be called
 * with an already-known-good literal, not arbitrary runtime input; `toMonologLevel()` also THROWS
 * `Psr\Log\InvalidArgumentException` (never returns a sentinel) on a truly unrecognised value, which
 * doesn't help satisfy the static type either. The real, statically-verifiable "is this a valid
 * level?" check is therefore done HERE, once, via `in_array($upper, Level::NAMES, true)`: PHPStan
 * narrows $upper's type to the exact `value-of<Level::NAMES>` literal union the moment that check
 * passes, which is what makes the subsequent `Level::fromName($upper)` call type-check. This narrows
 * accepted input to Monolog's canonical level NAMES (case-insensitively) — not Monolog's fuzzier
 * numeric-level-value support (e.g. POSTing `{"level":"400"}`), matching Spring Boot Actuator's own
 * /loggers, which likewise only accepts named levels.
 *
 * `LogManager::channel()` is declared to return the wide `\Psr\Log\LoggerInterface` (so that a
 * caller may in principle swap in ANY PSR-3 logger), but Laravel's own LogManager::get() always
 * concretely wraps the resolved driver in `Illuminate\Log\Logger` — including its emergency-logger
 * fallback (see LogManager::createEmergencyLogger()) — so the `instanceof LaravelLogger` guard below
 * is a real narrowing PHPStan can verify, not a defensive no-op; it is what makes ->getLogger()
 * (which only `Illuminate\Log\Logger`, not the PSR interface, declares) type-check.
 */
#[Component]
final class LoggersEndpoint implements ActuatorEndpoint
{
    public function __construct(
        private readonly LogManager $logs,
        private readonly Repository $config,
    ) {}

    public function endpointId(): string
    {
        return 'loggers';
    }

    public function enabled(): bool
    {
        return true;
    }

    public function handle(EndpointRequest $request): ?EndpointResponse
    {
        if ($request->method === 'POST') {
            return $this->setLevel($request);
        }

        /** @var array<int|string, mixed> $channels */
        $channels = (array) $this->config->get('logging.channels', []);

        $loggers = [];
        foreach ($channels as $name => $channel) {
            $level = is_array($channel) && isset($channel['level']) && is_string($channel['level'])
                ? strtoupper($channel['level'])
                : 'INFO';
            $loggers[(string) $name] = ['configuredLevel' => $level];
        }

        return EndpointResponse::json(['levels' => Level::NAMES, 'loggers' => $loggers]);
    }

    private function setLevel(EndpointRequest $request): ?EndpointResponse
    {
        $name = $request->subPath[0] ?? null;
        $level = isset($request->body['level']) && is_string($request->body['level']) ? $request->body['level'] : null;
        if ($name === null || $level === null) {
            return null;
        }

        $upper = strtoupper($level);
        if (! in_array($upper, Level::NAMES, true)) {
            return null;
        }

        $monologLevel = Level::fromName($upper);

        $channel = $this->logs->channel($name);
        if ($channel instanceof LaravelLogger) {
            $logger = $channel->getLogger();
            if ($logger instanceof Monolog) {
                foreach ($logger->getHandlers() as $handler) {
                    if ($handler instanceof AbstractHandler) {
                        $handler->setLevel($monologLevel);
                    }
                }
            }
        }

        return EndpointResponse::json(['name' => $name, 'configuredLevel' => $monologLevel->getName()]);
    }
}
