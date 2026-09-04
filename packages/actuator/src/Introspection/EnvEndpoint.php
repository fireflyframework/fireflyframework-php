<?php

declare(strict_types=1);

namespace Firefly\Actuator\Introspection;

use Firefly\Actuator\Endpoint\ActuatorEndpoint;
use Firefly\Actuator\Endpoint\EndpointRequest;
use Firefly\Actuator\Endpoint\EndpointResponse;
use Firefly\Container\Attributes\Component;
use Illuminate\Contracts\Config\Repository;

/**
 * Exposes the firefly.* configuration tree with sensitive values masked (fail-safe invariant: /env values masked).
 * A key matching password|secret|token|key|credential|passwd (case-insensitive) is replaced with ******.
 *
 * The rule itself moved to SensitiveValueMasker when /configprops arrived and needed the SAME rule — see that
 * class for why it is shared rather than copied, and for the array-valued-secret bypass this endpoint used to
 * have (a sensitive key holding an array was recursed into instead of masked, so a JWT keyring under
 * `firefly.security.jwt.keys` rendered every private key in full). This endpoint's observable contract is
 * unchanged for scalar values and strictly safer for array ones.
 *
 * Return type is narrowed to the non-nullable EndpointResponse (a legal covariant narrowing of
 * ActuatorEndpoint::handle()'s `?EndpointResponse`, the same idiom InfoEndpoint uses): /env has no
 * sub-resource concept to 404 on, so handle() always produces a body — PHPStan (level max) flags the
 * wider nullable type as dead code if left as `?EndpointResponse`.
 */
#[Component]
final class EnvEndpoint implements ActuatorEndpoint
{
    public function __construct(private readonly Repository $config) {}

    public function endpointId(): string
    {
        return 'env';
    }

    public function enabled(): bool
    {
        return true;
    }

    public function handle(EndpointRequest $request): EndpointResponse
    {
        /** @var array<string, mixed> $firefly */
        $firefly = (array) $this->config->get('firefly', []);

        return EndpointResponse::json(['firefly' => SensitiveValueMasker::mask($firefly)]);
    }
}
