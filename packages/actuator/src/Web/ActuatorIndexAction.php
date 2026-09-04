<?php

declare(strict_types=1);

namespace Firefly\Actuator\Web;

use Firefly\Actuator\Endpoint\ActuatorRegistry;
use Firefly\Actuator\Endpoint\ExposureModel;
use Firefly\Actuator\Server\ManagementPortGuard;
use Firefly\Actuator\Server\ManagementServerSettings;
use Firefly\Config\Config;
use Firefly\Kernel\Error\ErrorCategory;
use Firefly\Kernel\Error\ErrorSeverity;
use Firefly\Kernel\Exception\FireflyException;
use Firefly\Web\Exception\ProblemDetailsRenderer;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The HAL index at {base}: a `_links` map of every EXPOSED + enabled endpoint to its href. Mirrors Spring's
 * /actuator index so tooling can discover endpoints.
 *
 * The hrefs are built from ManagementServerSettings::mountPath(), NOT from the ExposureModel's base path alone:
 * `firefly.management.server.base-path` prefixes the mount, and an index advertising `/actuator/health` while the
 * router only answers `/manage/actuator/health` would hand every discovery client a set of dead links — the one
 * failure mode a HAL index exists to prevent.
 *
 * The index is guarded by ManagementPortGuard exactly as the dispatch action is, and returns the same problem+json
 * 404 rather than an empty `_links` object: an index that answered 200 with nothing in it on the application port
 * would confirm the actuator is mounted somewhere, which is the disclosure the management port is there to stop.
 */
final class ActuatorIndexAction
{
    public function __construct(
        private readonly ActuatorRegistry $registry,
        private readonly ExposureModel $exposure,
        private readonly Config $config,
        private readonly ManagementServerSettings $management,
        private readonly ManagementPortGuard $guard,
        private readonly ProblemDetailsRenderer $problems,
    ) {}

    public function __invoke(Request $request): Response
    {
        if (! $this->guard->permits($request)) {
            // The twin of ActuatorDispatchAction::notFound() — same status, same code, same renderer — so both
            // halves of the surface are indistinguishable from an unrouted URL on the wrong port.
            return $this->problems->render(
                new FireflyException('Not Found', 'RESOURCE_NOT_FOUND', 404, ErrorCategory::Framework, ErrorSeverity::Warning),
                $request,
            );
        }

        $base = rtrim($request->getSchemeAndHttpHost().'/'.$this->management->mountPath($this->exposure), '/');

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
