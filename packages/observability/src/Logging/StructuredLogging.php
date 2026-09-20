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
use Monolog\Logger as MonologLogger;

/**
 * Spring Boot 3.4's `logging.structured.format`, for Laravel's log channels: `firefly.logging.structured.format`
 * names a Monolog formatter (json — Monolog's JsonFormatter; ecs — the first-party EcsFormatter; logstash —
 * Monolog's LogstashFormatter with the framework's extra fields under `fields`) and apply() sets it on every
 * handler of a channel's real Monolog logger that can take one. The handlers themselves are never replaced,
 * added or removed: a `daily` file stays a daily file, a `stack` keeps its members — only what a line looks
 * like changes.
 *
 * WHICH CHANNELS. `firefly.logging.structured.channels` lists channel names; empty (the default) means the
 * default channel (`logging.default`). A stack channel's Monolog logger holds its MEMBERS' handler instances
 * (Illuminate\Log\LogManager::createStackDriver collects them), so applying to `stack` formats the members
 * too — and listing a member as well is harmless (setFormatter is idempotent).
 *
 * The service name is the same one tracing uses (`tracing.service-name`, else app.name) so a span and a log
 * line agree on who wrote them.
 */
final class StructuredLogging
{
    public const string FORMAT_KEY = 'firefly.logging.structured.format';

    public const string CHANNELS_KEY = 'firefly.logging.structured.channels';

    public function __construct(private readonly Config $config) {}

    /** `''` (off), `json`, `ecs` or `logstash`; anything else is a boot-time error. */
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

    /** @return list<string> */
    public function channels(): array
    {
        $channels = [];
        foreach ($this->config->array('firefly.logging.structured.channels', []) as $channel) {
            if (is_string($channel) && $channel !== '') {
                $channels[] = $channel;
            }
        }

        if ($channels !== []) {
            return array_values(array_unique($channels));
        }

        return [$this->config->string('logging.default', 'stack')];
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
            if ($handler instanceof FormattableHandlerInterface) {
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
