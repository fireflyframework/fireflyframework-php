<?php

declare(strict_types=1);

namespace Firefly\Admin\Web;

/**
 * One page of the dashboard: its slug, its nav label, and the actuator endpoint it needs.
 *
 * `requires` is the endpoint id a page cannot render without. The nav hides a page whose endpoint is absent
 * or switched off, rather than offering a link that lands on an apology — the actuator's own endpoints are
 * conditional (metrics disappears when firefly.observability.metrics.enabled is false), so the nav has to be
 * built from what this process actually registered.
 */
final readonly class AdminPage
{
    public function __construct(
        public string $slug,
        public string $label,
        public ?string $requires,
        public string $blurb,
    ) {}

    /** @return list<self> */
    public static function all(): array
    {
        return [
            new self('', 'Overview', null, 'Health, build information and what this process wired at boot.'),
            new self('beans', 'Beans', 'beans', 'Every bean the container registered, with its stereotype and scope.'),
            new self('conditions', 'Conditions', 'conditions', 'Which auto-configurations applied, and which backed off because you supplied your own.'),
            new self('mappings', 'Mappings', 'mappings', 'The compiled route table the dispatcher serves from.'),
            new self('scheduled', 'Scheduled', 'scheduledtasks', 'Tasks registered by #[Scheduled], with their cron or fixed rate.'),
            new self('metrics', 'Metrics', 'metrics', 'Counters, timers and gauges recorded through the meter registry.'),
            new self('loggers', 'Loggers', 'loggers', 'Log channels and their levels. Changing a level here affects this process only.'),
            new self('env', 'Environment', 'env', 'Resolved firefly.* configuration, with secrets masked.'),
        ];
    }
}
