<?php

declare(strict_types=1);

namespace Firefly\Admin\Settings;

/**
 * One switch the console may flip, and the reason it is on this list rather than on any other.
 *
 * THE LIST IS FIXED AND FRAMEWORK-OWNED. The console does not edit "configuration"; it edits a handful of
 * named BOOLEANS whose effect the framework can state. That is the difference between a feature switch and a
 * remote-configuration endpoint: an arbitrary key/value writer over `config()` would let a browser form set
 * `database.connections.mysql.host`, `app.key` or a logging path — and there is no gate that makes that a
 * good idea. Adding a toggle here is a deliberate act with a sentence attached.
 */
final readonly class FeatureToggle
{
    public function __construct(
        public string $key,
        public string $label,
        public string $group,
        public string $blurb,
        public bool $default = false,
    ) {}

    /** @return list<self> */
    public static function all(): array
    {
        return [
            new self('firefly.admin.data.enabled', 'Data browser', 'Dashboard',
                'Browse the records behind your repositories. Off by default: these are your customers\' rows, not your application\'s shape.'),
            new self('firefly.admin.data.writable', 'Data browser writes', 'Dashboard',
                'Permit create, edit and delete in the browser. Ineffective on its own — a write needs this AND the browser.'),
            new self('firefly.admin.data.relations', 'Entity relations', 'Dashboard',
                'Discover relations by calling the methods that declare one, so records link to what they reference.', true),
            new self('firefly.admin.datasource.probe', 'Connection probing', 'Dashboard',
                'Let the datasource page open a connection to report whether it answers.', true),

            new self('firefly.openapi.enabled', 'OpenAPI document', 'API',
                'Serve /openapi.json, generated from the compiled route and constraint manifests.', true),
            new self('firefly.openapi.viewer.enabled', 'API reference', 'API',
                'Serve the Swagger UI viewer over that document.', true),

            new self('firefly.observability.metrics.enabled', 'Metrics', 'Observability',
                'The MeterRegistry, the HTTP metrics filter, and the metrics and prometheus endpoints.', true),
            new self('firefly.management.enabled', 'Actuator', 'Observability',
                'The whole management surface. Off means every actuator endpoint 404s.', true),

            new self('firefly.web.error-page.enabled', 'Error page', 'Web',
                'Serve the LaraFly error page to browsers. Off falls back to Laravel\'s own.', true),
            new self('firefly.web.error-page.trace', 'Error page trace', 'Web',
                'Show the exception, its source and its stack trace on that page. Follows app.debug when unset.'),
        ];
    }

    /** @return list<string> the groups, in display order */
    public static function groups(): array
    {
        return ['Dashboard', 'API', 'Observability', 'Web'];
    }

    public static function find(string $key): ?self
    {
        foreach (self::all() as $toggle) {
            if ($toggle->key === $key) {
                return $toggle;
            }
        }

        return null;
    }
}
