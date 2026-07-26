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
 * Return type is narrowed to the non-nullable EndpointResponse (a legal covariant narrowing of
 * ActuatorEndpoint::handle()'s `?EndpointResponse`, the same idiom InfoEndpoint uses): /env has no
 * sub-resource concept to 404 on, so handle() always produces a body — PHPStan (level max) flags the
 * wider nullable type as dead code if left as `?EndpointResponse`.
 */
#[Component]
final class EnvEndpoint implements ActuatorEndpoint
{
    private const MASK = '******';

    private const SENSITIVE = '/password|secret|token|key|credential|passwd/i';

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

        return EndpointResponse::json(['firefly' => $this->mask($firefly)]);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function mask(array $values): array
    {
        $masked = [];
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                /** @var array<string, mixed> $value */
                $masked[$key] = $this->mask($value);

                continue;
            }
            $masked[$key] = preg_match(self::SENSITIVE, (string) $key) === 1 ? self::MASK : $value;
        }

        return $masked;
    }
}
