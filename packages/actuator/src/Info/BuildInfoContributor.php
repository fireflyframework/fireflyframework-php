<?php

declare(strict_types=1);

namespace Firefly\Actuator\Info;

use Firefly\Config\Config;
use Firefly\Container\Attributes\Component;

/**
 * Reads a generated build-info JSON file (firefly.management.info.build.path, default firefly-build.json in the
 * CWD) and surfaces it under the `build` key — closing pyfly's missing-BuildInfo gap. Absent/invalid file → no
 * fragment (never an error).
 */
#[Component]
final class BuildInfoContributor implements InfoContributor
{
    public function __construct(private readonly Config $config) {}

    /**
     * @return array<string, mixed>
     */
    public function info(): array
    {
        $path = $this->config->string('firefly.management.info.build.path', (getcwd() ?: '.').'/firefly-build.json');
        if (! is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            return [];
        }

        /** @var array<string, mixed> $decoded */
        return ['build' => $decoded];
    }
}
