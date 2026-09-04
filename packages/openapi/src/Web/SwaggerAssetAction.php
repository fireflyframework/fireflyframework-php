<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Web;

use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Serves one file of the official Swagger UI distribution from the application's own origin.
 *
 * Only the basenames SwaggerAssets whitelists are servable, and each is realpath-checked to be inside the
 * dist directory, so a crafted `..` cannot walk out of it. Anything else is a plain 404 — deliberately not a
 * problem+json body, because the caller here is a browser fetching a stylesheet, not an API client.
 *
 * The files are immutable for a given installed version of swagger-api/swagger-ui, so they are sent with a
 * long-lived cache header. That is what keeps the console feeling instant on every page view after the first
 * without the framework maintaining a cache-busting scheme of its own: composer changes the file contents
 * only when the pinned version changes, and the ETag changes with them.
 */
final class SwaggerAssetAction
{
    public function __construct(private readonly SwaggerAssets $assets) {}

    public function __invoke(string $file): SymfonyResponse
    {
        $path = $this->assets->path($file);
        $type = $this->assets->contentType($file);

        if ($path === null || $type === null) {
            return new Response('Not Found', 404, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        $response = new BinaryFileResponse($path, 200, ['Content-Type' => $type]);
        $response->setPublic();
        $response->setMaxAge(31536000);
        $response->setImmutable();
        $response->setAutoEtag();

        return $response;
    }
}
