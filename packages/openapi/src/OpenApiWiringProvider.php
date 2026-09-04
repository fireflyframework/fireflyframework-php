<?php

declare(strict_types=1);

namespace Firefly\OpenApi;

use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\FireflyServiceProvider;
use Firefly\OpenApi\Boot\OpenApiRouteRegistrar;
use Firefly\OpenApi\Command\OpenApiCommand;

/**
 * The boot-pass half of firefly/openapi. It cannot ride on OpenApiServiceProvider, because
 * AutoConfiguration's register() is final and records manifest candidacy only — the same split every Firefly
 * capability package carries, and the reason both providers appear in extra.laravel.providers.
 *
 * NOTHING IS BOUND HERE, deliberately. Every collaborator this package owns is a #[Bean] on
 * OpenApiAutoConfiguration, and the two manifests it reads (RouteManifest, ConstraintManifest) belong to
 * firefly/web. A bound()-guarded default for either of those would be worse than useless: Laravel discovers
 * package providers alphabetically, `Firefly\OpenApi\…` sorts before `Firefly\Web\…`, and first-one-wins
 * means an empty fallback registered here would beat WebServiceProvider's real binding and silently produce
 * a document with no paths in it. So this provider contributes exactly one pass and one console command.
 *
 * The command is registered from boot() rather than passes(): it is an Artisan concern with no place in the
 * boot pipeline, and `commands()` is a no-op outside a console process anyway. This is the CliServiceProvider
 * idiom, applied locally so that firefly/openapi needs no dependency on firefly/cli (which is require-dev in
 * a real app and absent from the firefly/firefly metapackage — a command registered over there would be
 * missing exactly where it is most useful).
 */
final class OpenApiWiringProvider extends FireflyServiceProvider
{
    /**
     * @return list<BootPass>
     */
    public function passes(): array
    {
        return [new OpenApiRouteRegistrar];
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([OpenApiCommand::class]);
        }
    }
}
