<?php

declare(strict_types=1);

namespace Firefly\OpenApi;

use Firefly\Config\Config;

/**
 * The config-derived shape of the generated document and of the two routes that serve it.
 *
 * Read ONCE, at BootPhase::FlushDefinitions, into an immutable value object — the same lifetime
 * ExposureModel has in firefly/actuator, and for the same reason: OpenApiRouteRegistrar mounts the spec and
 * viewer routes at BootPhase::WiringPasses from `specPath`/`viewerPath`, so a post-boot `config()->set()` on
 * those keys could not move an already-mounted route anyway. Anything that MUST be live per request (there is
 * nothing here today) would have to read Config at request time instead, exactly as ActuatorDispatchAction
 * does for its per-endpoint enable flag.
 *
 * Attribute routes cannot carry a configurable path — `#[GetMapping('/openapi.json')]` bakes the literal into
 * a compiled RouteDescriptor — which is precisely why this package mounts its two routes natively on the
 * illuminate Router from a BootPass, the ActuatorRouteRegistrar precedent, rather than shipping a
 * #[RestController] of its own. A framework package whose own endpoints appeared in the app's RouteManifest
 * would also end up documenting ITSELF in the spec it generates.
 */
final readonly class OpenApiProperties
{
    /**
     * @param  list<array{url: string, description?: string}>  $servers
     * @param  list<string>  $excludePathPrefixes
     */
    public function __construct(
        public bool $enabled,
        public string $specPath,
        public bool $viewerEnabled,
        public string $viewerPath,
        public bool $viewerCdn,
        public string $title,
        public string $version,
        public string $description,
        public array $servers,
        public array $excludePathPrefixes,
        public bool $includeHtml = false,
    ) {}

    public static function fromConfig(Config $config): self
    {
        return new self(
            enabled: $config->bool('firefly.openapi.enabled', true),
            specPath: self::path($config->string('firefly.openapi.path', '/openapi.json'), 'openapi.json'),
            viewerEnabled: $config->bool('firefly.openapi.viewer.enabled', true),
            viewerPath: self::path($config->string('firefly.openapi.viewer.path', '/openapi'), 'openapi'),
            viewerCdn: $config->bool('firefly.openapi.viewer.cdn', false),
            title: $config->string('firefly.openapi.title', 'API'),
            version: $config->string('firefly.openapi.version', '0.0.0'),
            description: $config->string('firefly.openapi.description', ''),
            servers: self::servers($config),
            excludePathPrefixes: self::csv($config->string('firefly.openapi.exclude', '')),
            includeHtml: $config->bool('firefly.openapi.include-html', false),
        );
    }

    /**
     * Both routes are registered with the leading slash stripped, because Illuminate's Router does that
     * itself (Route::__construct -> uri = trim($uri, '/')) and a `/`-prefixed literal would otherwise make
     * every generated link in the viewer disagree with the route it points at by one character.
     */
    private static function path(string $configured, string $fallback): string
    {
        $trimmed = trim($configured, '/');

        return $trimmed === '' ? $fallback : $trimmed;
    }

    /**
     * `firefly.openapi.servers` accepts the two spellings a real config file uses: a CSV/array of bare URL
     * strings (`['https://api.example.test']`) and OpenAPI's own object form
     * (`[['url' => '...', 'description' => '...']]`). Anything else in the list is dropped rather than
     * emitted, because a Server Object with no `url` is invalid per the 3.1 schema and would poison an
     * otherwise-good document.
     *
     * @return list<array{url: string, description?: string}>
     */
    private static function servers(Config $config): array
    {
        $raw = $config->array('firefly.openapi.servers', []);

        $servers = [];
        foreach ($raw as $entry) {
            if (is_string($entry) && $entry !== '') {
                $servers[] = ['url' => $entry];

                continue;
            }

            if (! is_array($entry) || ! isset($entry['url']) || ! is_string($entry['url']) || $entry['url'] === '') {
                continue;
            }

            $server = ['url' => $entry['url']];
            if (isset($entry['description']) && is_string($entry['description'])) {
                $server['description'] = $entry['description'];
            }

            $servers[] = $server;
        }

        return $servers;
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
