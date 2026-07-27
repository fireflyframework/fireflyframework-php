<?php

declare(strict_types=1);

namespace Firefly\Cli\Introspection;

use Firefly\Actuator\Endpoint\ActuatorRegistry;
use Firefly\Actuator\Endpoint\EndpointRequest;
use Illuminate\Console\Command;

/**
 * Renders M12 actuator/observability endpoint data at the CLI, in-process (no HTTP round-trip). Thin:
 * it only resolves the registry, calls handle(), and prints the EndpointResponse — it reimplements NO
 * actuator logic. ActuatorRegistry is populated by ActuatorRouteRegistrar at BootPhase::WiringPasses,
 * strictly before any command's handle() runs (Artisan boots the application before dispatching any
 * command), so resolving it here — inside render(), called from each command's handle() — is correct.
 */
final class ActuatorCliRenderer
{
    /** @param  list<string>  $subPath */
    public function render(Command $command, string $endpointId, array $subPath = []): int
    {
        /** @var ActuatorRegistry $registry */
        $registry = $command->getLaravel()->make(ActuatorRegistry::class);
        $endpoint = $registry->get($endpointId);

        if ($endpoint === null) {
            $command->warn(sprintf('endpoint [%s] is not available (disabled or not wired).', $endpointId));

            return Command::SUCCESS;
        }

        $response = $endpoint->handle(new EndpointRequest('GET', $subPath));
        if ($response === null) {
            $command->warn(sprintf('endpoint [%s] returned no content.', $endpointId));

            return Command::SUCCESS;
        }

        $command->line(is_array($response->body)
            ? (string) json_encode($response->body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            : $response->body);

        return $response->status < 400 ? Command::SUCCESS : Command::FAILURE;
    }
}
