<?php

declare(strict_types=1);

namespace Firefly\Admin\Settings;

use Firefly\Config\Config;

/**
 * Whether the settings console exists, whether it may write, and whether this is production.
 *
 * `production` is read once here and is the gate no key can lift — see SettingsConsole for why the last gate
 * is deliberately not configurable.
 */
final readonly class SettingsSettings
{
    public function __construct(
        public bool $enabled = false,
        public bool $writable = false,
        public bool $production = true,
    ) {}

    public static function fromConfig(Config $config): self
    {
        $environment = strtolower($config->string('app.env', 'production'));

        return new self(
            // OFF by default, unlike every other dashboard page. The others describe the application; this
            // one changes it, and a surface that changes a running system should never appear because
            // somebody left a debug flag on.
            enabled: $config->bool('firefly.admin.settings.enabled', false),
            writable: $config->bool('firefly.admin.settings.writable', false),
            // `prod` is included because it is what half of every deployment actually writes in APP_ENV, and
            // a gate that only recognised the long spelling would be off in exactly those deployments.
            production: in_array($environment, ['production', 'prod'], true),
        );
    }
}
