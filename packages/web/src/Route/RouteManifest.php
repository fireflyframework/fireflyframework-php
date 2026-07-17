<?php

declare(strict_types=1);

namespace Firefly\Web\Route;

use Firefly\Kernel\Exception\Framework\ConfigurationException;

/**
 * The compiled, reflection-free route source the RouteWiringPass reads at boot. Loaded via require+map; the
 * binding plans are pure arrays, so the whole manifest is a plain PHP array literal.
 *
 * @phpstan-import-type Binding from RouteDescriptor
 */
final class RouteManifest
{
    /**
     * @param  list<RouteDescriptor>  $routes
     */
    public function __construct(private readonly array $routes) {}

    /**
     * @param  array<int, array{httpMethod: string, path: string, controllerClass: string, methodName: string, status: int, name: string|null, bindings: list<Binding>}>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(array_map(
            static fn (array $row): RouteDescriptor => RouteDescriptor::fromArray($row),
            array_values($data),
        ));
    }

    public static function load(string $path): self
    {
        if (! is_file($path)) {
            throw new ConfigurationException("Route manifest not found at {$path}. Run the route scan first.");
        }

        /** @var mixed $data */
        $data = require $path;
        if (! is_array($data)) {
            throw new ConfigurationException("Route manifest at {$path} did not return an array.");
        }

        /** @var array<int, array{httpMethod: string, path: string, controllerClass: string, methodName: string, status: int, name: string|null, bindings: list<Binding>}> $data */
        return self::fromArray($data);
    }

    /**
     * @return list<RouteDescriptor>
     */
    public function all(): array
    {
        return $this->routes;
    }
}
