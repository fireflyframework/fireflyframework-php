<?php

declare(strict_types=1);

namespace Firefly\Observability\Logging;

use Firefly\Config\Config;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Observability\Logging\Formatter\EcsFormatter;
use Monolog\Formatter\FormatterInterface;
use Monolog\Formatter\JsonFormatter;
use Monolog\Formatter\LogstashFormatter;
use Monolog\Handler\FormattableHandlerInterface;
use Monolog\Handler\GroupHandler;
use Monolog\Logger as MonologLogger;

/**
 * Spring Boot 3.4's `logging.structured.format`, for Laravel's log channels: `firefly.logging.structured.format`
 * names a Monolog formatter (json — Monolog's JsonFormatter; ecs — the first-party EcsFormatter; logstash —
 * Monolog's LogstashFormatter with the framework's extra fields under `fields`) and apply() sets it on every
 * handler of a channel's real Monolog logger that can take one: a FormattableHandlerInterface (every leaf
 * handler and the wrappers Laravel builds — FingersCrossed, Buffer, Filter, Sampling, Deduplication), or a
 * GroupHandler, which declares the same setFormatter() forwarding to its formattable members WITHOUT
 * implementing the interface — the WhatFailureGroupHandler Laravel wraps a stack's members in when
 * `logging.channels.<stack>.ignore_exceptions` is true is one, and an interface check alone would leave every
 * member of such a stack on LineFormatter with nothing to say so. The handlers themselves are never replaced,
 * added or removed: a `daily` file stays a daily file, a `stack` keeps its members — only what a line looks
 * like changes.
 *
 * WHICH CHANNELS. `firefly.logging.structured.channels` lists channel names; empty (the default) means the
 * default channel (`logging.default`). A stack channel's Monolog logger holds its MEMBERS' handler instances
 * (Illuminate\Log\LogManager::createStackDriver collects them), so applying to `stack` formats the members
 * too — and listing a member as well is harmless (setFormatter is idempotent). Every listed name must exist
 * under `logging.channels`, and channels() refuses one that does not, the way format() refuses a format it
 * does not know: LogManager::channel() never throws for an unknown name — it catches its own "Log [x] is not
 * defined." and hands back a throw-away emergency logger — so without this check a typo would put the id
 * processors and the formatter on an object nobody writes to while the real channel silently kept plain text
 * without a single id. The default fallback is not checked here: a `logging.default` that names nothing is
 * Laravel's own misconfiguration, and its emergency logger is loud about it on every write (LogChannelWiring
 * skips it, through defines(), rather than building that emergency logger at boot).
 *
 * WHEN THE REFUSAL HAPPENS. format() and channels() throw wherever they are called, and LogChannelWiringPass
 * calls both from Application::boot(), before it touches the log service — THAT is what makes either a
 * boot-time error. ObservabilityWiringProvider's afterResolving('log') hook calls them too, but a throw from
 * a resolving callback is not something the application can be relied on to see: Container::resolve() has
 * cached the singleton by then, and Laravel's exception handler swallows whatever the first resolution of
 * `log` throws when that resolution is its own report() — see the pass.
 *
 * The service name is the same one tracing uses (`tracing.service-name`, else app.name) so a span and a log
 * line agree on who wrote them.
 */
final class StructuredLogging
{
    public const string FORMAT_KEY = 'firefly.logging.structured.format';

    public const string CHANNELS_KEY = 'firefly.logging.structured.channels';

    public function __construct(private readonly Config $config) {}

    /**
     * `''` (off), `json`, `ecs` or `logstash`; anything else is a ConfigurationException — a boot-time error,
     * because LogChannelWiringPass calls this from Application::boot().
     */
    public function format(): string
    {
        $format = $this->config->string('firefly.logging.structured.format', '');

        if (! in_array($format, ['', 'json', 'ecs', 'logstash'], true)) {
            throw new ConfigurationException(
                "Unknown structured log format '{$format}' (firefly.logging.structured.format); use json, ecs, logstash, or '' for plain text.",
            );
        }

        return $format;
    }

    /**
     * The configured list, each name checked against `logging.channels` (one that is not defined there is a
     * ConfigurationException — a boot-time error, because LogChannelWiringPass calls this from
     * Application::boot()); `[logging.default]` when it is empty, unchecked.
     *
     * @return list<string>
     */
    public function channels(): array
    {
        $channels = [];
        foreach ($this->config->array('firefly.logging.structured.channels', []) as $channel) {
            if (! is_string($channel) || $channel === '') {
                continue;
            }

            if (! $this->defines($channel)) {
                throw new ConfigurationException(
                    "Unknown log channel '{$channel}' (firefly.logging.structured.channels); it is not defined under logging.channels.",
                );
            }

            $channels[] = $channel;
        }

        if ($channels !== []) {
            return array_values(array_unique($channels));
        }

        return [$this->config->string('logging.default', 'stack')];
    }

    /**
     * Whether `logging.channels.<name>` is defined — the exact test LogManager::resolve() fails on
     * (`is_null($config)`), which Repository::has() would get wrong for a null entry.
     */
    public function defines(string $channel): bool
    {
        return $this->config->get("logging.channels.{$channel}") !== null;
    }

    public function formatter(): ?FormatterInterface
    {
        return match ($this->format()) {
            '' => null,
            'json' => new JsonFormatter,
            'ecs' => new EcsFormatter,
            'logstash' => new LogstashFormatter($this->serviceName(), null, 'fields', 'context'),
            default => null,
        };
    }

    public function apply(MonologLogger $monolog): void
    {
        $formatter = $this->formatter();
        if ($formatter === null) {
            return;
        }

        $monolog->pushProcessor(new ServiceContextLogProcessor($this->serviceName(), $this->environment()));

        foreach ($monolog->getHandlers() as $handler) {
            if ($handler instanceof FormattableHandlerInterface || $handler instanceof GroupHandler) {
                $handler->setFormatter($formatter);
            }
        }
    }

    public function serviceName(): string
    {
        $name = $this->config->string('firefly.observability.tracing.service-name', '');

        return $name !== '' ? $name : $this->config->string('app.name', 'laravel');
    }

    public function environment(): string
    {
        return $this->config->string('app.env', 'production');
    }
}
