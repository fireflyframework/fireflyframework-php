<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Web;

use Firefly\OpenApi\Generator\OpenApiGenerator;
use Illuminate\Http\Response;

/**
 * Serves the generated document. A plain invokable resolved out of the container by OpenApiRouteRegistrar's
 * route closure — the ActuatorIndexAction/ActuatorDispatchAction idiom — rather than a #[RestController],
 * because an attribute-routed controller could not sit at a configurable path and would document itself into
 * the very spec it serves.
 *
 * The media type is `application/json`, not the more precise `application/openapi+json;version=3.1`. That is
 * a deliberate compatibility choice: the OpenAPI media type is registered but poorly supported, and several
 * widely used clients (including the generator toolchains this package exists to feed) refuse a document
 * whose Content-Type they do not recognise. The document says `"openapi": "3.1.0"` in its first member, which
 * is how every consumer actually detects the version.
 */
final class OpenApiSpecAction
{
    public function __construct(private readonly OpenApiGenerator $generator) {}

    public function __invoke(): Response
    {
        return new Response(
            $this->generator->toJson(),
            200,
            ['Content-Type' => 'application/json'],
        );
    }
}
