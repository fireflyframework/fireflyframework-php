<?php

declare(strict_types=1);

namespace Firefly\Admin\Web;

/**
 * One page of the dashboard: its slug, nav label, group, the actuator endpoint it needs, and a one-line
 * blurb used as the page's own subtitle.
 *
 * `requires` is the endpoint id a page cannot render without. The nav HIDES a page whose endpoint is absent
 * or switched off rather than offering a link that lands on an apology — the actuator's endpoints are
 * conditional (metrics disappears when firefly.observability.metrics.enabled is false, configprops and
 * httpexchanges only exist if those packages are installed), so the menu has to be built from what this
 * process actually registered.
 *
 * `group` exists because a flat list of eleven links is a worse menu than three short ones. The grouping is
 * the operator's mental model, not the package layout: what is it doing right now, what did it wire at boot,
 * and how is it configured.
 */
final readonly class AdminPage
{
    public const GROUP_RUNTIME = 'Runtime';

    public const GROUP_WIRING = 'Wiring';

    public const GROUP_CONFIG = 'Configuration';

    public const GROUP_DATA = 'Data';

    public function __construct(
        public string $slug,
        public string $label,
        public ?string $requires,
        public string $group,
        public string $blurb,
    ) {}

    /** @return list<self> */
    public static function all(): array
    {
        return [
            new self('', 'Overview', null, self::GROUP_RUNTIME,
                'Health, runtime and what this process wired at boot.'),
            new self('health', 'Health', 'health', self::GROUP_RUNTIME,
                'Every health indicator this process registered, with its own status and details.'),
            new self('metrics', 'Metrics', 'metrics', self::GROUP_RUNTIME,
                'Counters, timers and gauges recorded through the meter registry.'),
            new self('http', 'HTTP traffic', 'httpexchanges', self::GROUP_RUNTIME,
                'The most recent requests this application served.'),

            new self('beans', 'Beans', 'beans', self::GROUP_WIRING,
                'Every bean the container registered, with the stereotype that declared it.'),
            new self('graph', 'Bean graph', 'beans', self::GROUP_WIRING,
                'How your beans depend on one another, resolved through the interfaces they are wired by.'),
            new self('conditions', 'Conditions', 'conditions', self::GROUP_WIRING,
                'Which auto-configurations applied, and which backed off because you supplied your own.'),
            new self('mappings', 'Routes', 'mappings', self::GROUP_WIRING,
                'The compiled route table the dispatcher serves from.'),
            new self('scheduled', 'Scheduled', 'scheduledtasks', self::GROUP_WIRING,
                'Methods registered by #[Scheduled], with the cron or interval that drives them.'),

            new self('env', 'Environment', 'env', self::GROUP_CONFIG,
                'Resolved firefly.* configuration, with secrets masked.'),
            new self('configprops', 'Config properties', 'configprops', self::GROUP_CONFIG,
                'Every #[ConfigProperties] DTO the application bound, with the values it resolved.'),
            new self('caches', 'Caches', 'caches', self::GROUP_CONFIG,
                'The cache stores this application has configured.'),
            new self('loggers', 'Loggers', 'loggers', self::GROUP_CONFIG,
                'Log channels and their levels.'),

            // Both Data pages have a null `requires`: they read the container, not an actuator endpoint.
            // Datasource is offered whenever a database manager is bound; the browser has its own switch on
            // top of that — see AdminAction::nav().
            new self('datasource', 'Datasource', null, self::GROUP_DATA,
                'Connections, persistence settings and the compiled #[Transactional] contract.'),
            new self('data', 'Browse data', null, self::GROUP_DATA,
                'Every repository this application declared, and the records behind it.'),
        ];
    }

    /**
     * The groups in menu order, so the nav does not depend on array_unique's ordering guarantees.
     *
     * @return list<string>
     */
    public static function groups(): array
    {
        return [self::GROUP_RUNTIME, self::GROUP_WIRING, self::GROUP_DATA, self::GROUP_CONFIG];
    }
}
