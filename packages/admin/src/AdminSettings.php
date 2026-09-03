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
    public function __construct(
        public bool $enabled,
        public string $basePath,
        public string $title,
    ) {}

    public static function fromConfig(Config $config): self
    {
        $base = trim($config->string('firefly.admin.base-path', '/firefly'), '/');

        return new self(
            enabled: $config->bool('firefly.admin.enabled', $config->bool('app.debug', false)),
            basePath: $base === '' ? 'firefly' : $base,
            title: $config->string('firefly.admin.title', $config->string('app.name', 'LaraFly')),
        );
    }

    /** An absolute path for a dashboard page, e.g. url('beans') => /firefly/beans. */
    public function url(string $page = ''): string
    {
        return '/'.$this->basePath.($page === '' ? '' : '/'.$page);
    }
}
