<?php

declare(strict_types=1);

namespace Firefly\OpenApi;

use Firefly\AutoConfigure\AutoConfiguration;

/**
 * The discovered auto-configuration provider (extra.laravel.providers). Extending AutoConfiguration means its
 * final register() records manifest CANDIDACY only — the compiled artifacts in cache/ describe
 * OpenApiAutoConfiguration's six #[Bean]s and their #[ConditionalOnMissingBean] guards, so a bare-skeleton
 * boot with no app scan configured still gets them. The boot-pass half rides on OpenApiWiringProvider, never
 * here; the two are listed together in composer.json, exactly as firefly/actuator lists its own pair.
 */
final class OpenApiServiceProvider extends AutoConfiguration
{
    protected function componentManifestPath(): string
    {
        return __DIR__.'/../cache/firefly-openapi-components.php';
    }

    protected function contextManifestPath(): string
    {
        return __DIR__.'/../cache/firefly-openapi-context.php';
    }
}
