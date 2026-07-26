<?php

declare(strict_types=1);

namespace Firefly\Actuator\Web;

use Firefly\Actuator\Endpoint\ActuatorRegistry;
use Firefly\Actuator\Endpoint\ExposureModel;
use Firefly\Config\Config;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The HAL index at {base}: a `_links` map of every EXPOSED + enabled endpoint to its href. Mirrors Spring's
 * /actuator index so tooling can discover endpoints.
 */
final class ActuatorIndexAction
{
    public function __construct(
        private readonly ActuatorRegistry $registry,
        private readonly ExposureModel $exposure,
        private readonly Config $config,
    ) {}

    public function __invoke(Request $request): Response
    {
        $base = rtrim($request->getSchemeAndHttpHost().'/'.$this->exposure->basePath, '/');

        $links = ['self' => ['href' => $base]];
        foreach ($this->registry->all() as $id => $endpoint) {
            if (! $endpoint->enabled() || ! $this->config->bool("firefly.management.endpoint.{$id}.enabled", true) || ! $this->exposure->isExposed($id)) {
                continue;
            }
            $links[$id] = ['href' => $base.'/'.$id];
        }

        return new Response(
            (string) json_encode(['_links' => $links], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            200,
            ['Content-Type' => 'application/json'],
        );
    }
}
