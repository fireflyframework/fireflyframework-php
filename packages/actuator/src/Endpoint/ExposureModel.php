<?php

declare(strict_types=1);

namespace Firefly\Actuator\Endpoint;

use Firefly\Config\Config;

/**
 * The web exposure model: firefly.management.endpoints.web.exposure.include/.exclude (CSV or "*", exclude wins) plus
 * .base-path (default /actuator). Secure-default include = "health,info" — a sensitive endpoint absent from include
 * is NOT exposed, so the dispatch action 404s it (secure-by-default; §7 risk 5). Per-endpoint enable/disable
 * (firefly.management.endpoint.{id}.enabled) is checked separately at dispatch (needs the live Config).
 */
final readonly class ExposureModel
{
    /**
     * @param  list<string>  $include
     * @param  list<string>  $exclude
     */
    public function __construct(
        private array $include,
        private array $exclude,
        public string $basePath,
    ) {}

    public static function fromConfig(Config $config): self
    {
        $include = self::csv($config->string('firefly.management.endpoints.web.exposure.include', 'health,info'));
        $exclude = self::csv($config->string('firefly.management.endpoints.web.exposure.exclude', ''));
        $base = trim($config->string('firefly.management.endpoints.web.base-path', '/actuator'), '/');

        return new self($include, $exclude, $base === '' ? 'actuator' : $base);
    }

    public function isExposed(string $id): bool
    {
        if (in_array($id, $this->exclude, true)) {
            return false;
        }

        if (in_array('*', $this->include, true)) {
            return true;
        }

        return in_array($id, $this->include, true);
    }

    /**
     * @return list<string>
     */
    private static function csv(string $value): array
    {
        return array_values(array_filter(
            array_map('trim', explode(',', $value)),
            static fn (string $segment): bool => $segment !== '',
        ));
    }
}
