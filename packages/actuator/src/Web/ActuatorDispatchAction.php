<?php

declare(strict_types=1);

namespace Firefly\Actuator\Web;

use Firefly\Actuator\Endpoint\ActuatorRegistry;
use Firefly\Actuator\Endpoint\EndpointRequest;
use Firefly\Actuator\Endpoint\EndpointResponse;
use Firefly\Actuator\Endpoint\ExposureModel;
use Firefly\Config\Config;
use Firefly\Kernel\Error\ErrorCategory;
use Firefly\Kernel\Error\ErrorSeverity;
use Firefly\Kernel\Exception\FireflyException;
use Firefly\Web\Exception\ProblemDetailsRenderer;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

/**
 * The single invokable behind {base}/{path}. Splits {path} into [id, ...subPath], enforces enabled() + the
 * per-endpoint config flag + exposure (unexposed/disabled/unknown → 404), then dispatches. A null handle() result
 * is 404; an EndpointResponse maps to an illuminate Response; any thrown error renders via ProblemDetailsRenderer
 * (fail-safe — never leaks internals as a raw 500).
 */
final class ActuatorDispatchAction
{
    public function __construct(
        private readonly ActuatorRegistry $registry,
        private readonly ExposureModel $exposure,
        private readonly Config $config,
        private readonly ProblemDetailsRenderer $problems,
    ) {}

    public function __invoke(Request $request, string $path): Response
    {
        try {
            $segments = array_values(array_filter(explode('/', $path), static fn (string $s): bool => $s !== ''));
            $id = $segments[0] ?? '';
            $subPath = array_slice($segments, 1);

            $endpoint = $this->registry->get($id);
            $allowed = $endpoint !== null
                && $endpoint->enabled()
                && $this->config->bool("firefly.management.endpoint.{$id}.enabled", true)
                && $this->exposure->isExposed($id);

            if (! $allowed) {
                return $this->problems->render($this->notFound(), $request);
            }

            /** @var array<string, mixed> $query */
            $query = $request->query();
            /** @var array<string, mixed> $body */
            $body = $request->isJson() ? (array) $request->json()->all() : $request->request->all();

            $result = $endpoint->handle(new EndpointRequest($request->getMethod(), $subPath, $query, $body));
            if ($result === null) {
                return $this->problems->render($this->notFound(), $request);
            }

            return $this->toResponse($result);
        } catch (Throwable $e) {
            return $this->problems->render($e, $request);
        }
    }

    private function toResponse(EndpointResponse $response): Response
    {
        $body = is_string($response->body)
            ? $response->body
            : (string) json_encode($response->body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return new Response($body, $response->status, ['Content-Type' => $response->contentType]);
    }

    private function notFound(): FireflyException
    {
        return new FireflyException('Not Found', 'RESOURCE_NOT_FOUND', 404, ErrorCategory::Framework, ErrorSeverity::Warning);
    }
}
