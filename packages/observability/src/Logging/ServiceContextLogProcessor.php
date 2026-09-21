<?php

declare(strict_types=1);

namespace Firefly\Observability\Logging;

use Monolog\LogRecord;

/**
 * Names the application on every record — `service_name` and `service_environment` in extra, which the ECS
 * formatter lifts into `service.name`/`service.environment` and the JSON and Logstash formats carry as they
 * are. Pushed only when a structured format is on: on a plain text line these two would be repeated noise.
 */
final class ServiceContextLogProcessor
{
    public const string SERVICE_NAME = 'service_name';

    public const string SERVICE_ENVIRONMENT = 'service_environment';

    public function __construct(
        private readonly string $serviceName,
        private readonly string $environment,
    ) {}

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(extra: [
            ...$record->extra,
            self::SERVICE_NAME => $this->serviceName,
            self::SERVICE_ENVIRONMENT => $this->environment,
        ]);
    }
}
