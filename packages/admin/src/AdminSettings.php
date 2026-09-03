<?php

declare(strict_types=1);

namespace Firefly\Admin;

use Firefly\Config\Config;

/**
 * Where the dashboard is mounted, and whether it is mounted at all.
 *
 * SECURITY — read this before changing the default. The dashboard reads its endpoints IN-PROCESS from
 * Actuator's registry, deliberately bypassing ExposureModel: the whole point of a local dashboard is to see
 * beans, conditions and the environment without first publishing them over HTTP to everyone. That makes the
 * dashboard's own URL the only boundary, so it must not be on by default in production.
 *
 * `firefly.admin.enabled` therefore defaults to the value of `app.debug`. An application already running with
 * debug on is already serving stack traces and is a development environment by definition, so a dashboard
 * there is consistent; an application with debug off must opt in explicitly, and should put the route behind
 * its own auth middleware when it does. Setting the key always wins over the debug default, in both
 * directions.
 */
final readonly class AdminSettings
{
    /**
     * @param  list<string>  $excludedPages  page slugs hidden from the menu and refused by the router
     */
    public function __construct(
        public bool $enabled,
        public string $basePath,
        public string $title,
        public int $refreshSeconds = 10,
        public string $theme = 'auto',
        public int $graphMaxNodes = 220,
        public array $excludedPages = [],
    ) {}

    public static function fromConfig(Config $config): self
    {
        $base = trim($config->string('firefly.admin.base-path', '/firefly'), '/');

        return new self(
            enabled: $config->bool('firefly.admin.enabled', $config->bool('app.debug', false)),
            basePath: $base === '' ? 'firefly' : $base,
            title: $config->string('firefly.admin.title', $config->string('app.name', 'LaraFly')),
            // Floored at 2s: a shorter interval reloads faster than a page renders, so the countdown would
            // never finish and the dashboard would hammer the application it is supposed to be observing.
            refreshSeconds: max(2, $config->int('firefly.admin.refresh-seconds', 10)),
            theme: self::theme($config->string('firefly.admin.theme', 'auto')),
            // Past this, a dependency diagram is a hairball rather than something anyone can read, so the
            // graph page lists the relations instead of drawing them. Configurable because "unreadable"
            // depends on the screen and the application.
            graphMaxNodes: max(0, $config->int('firefly.admin.graph.max-nodes', 220)),
            excludedPages: self::csv($config->string('firefly.admin.pages.exclude', '')),
        );
    }

    /** An unrecognised theme falls back to following the operating system rather than rendering unstyled. */
    private static function theme(string $configured): string
    {
        $theme = strtolower(trim($configured));

        return in_array($theme, ['auto', 'light', 'dark'], true) ? $theme : 'auto';
    }

    /**
     * Whether a page may be reached at all.
     *
     * `firefly.admin.pages.exclude` is a hard refusal, not a menu preference: the page is hidden AND its URL
     * 404s. A deployment that hides `env` because it is uncomfortable having resolved configuration one
     * click away has not achieved anything if the URL still answers.
     */
    public function allows(string $slug): bool
    {
        return ! in_array($slug === '' ? 'overview' : $slug, $this->excludedPages, true);
    }

    /**
     * @return list<string>
     */
    private static function csv(string $value): array
    {
        return array_values(array_filter(
            array_map(static fn (string $part): string => strtolower(trim($part)), explode(',', $value)),
            static fn (string $part): bool => $part !== '',
        ));
    }

    /** An absolute path for a dashboard page, e.g. url('beans') => /firefly/beans. */
    public function url(string $page = ''): string
    {
        return '/'.$this->basePath.($page === '' ? '' : '/'.$page);
    }
}
