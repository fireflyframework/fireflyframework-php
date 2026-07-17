<?php

declare(strict_types=1);

namespace Firefly\Web\Route;

/**
 * A single compiled route: the HTTP method, full path, controller/method, default status, optional Laravel
 * route name, and a pure-array binding plan (no closures/objects) so it var_exports cleanly.
 *
 * @phpstan-type Binding array{name: string, kind: string, key: string, type: string|null, required: bool, default: mixed, valid: bool, properties: list<string>}
 */
final readonly class RouteDescriptor
{
    /**
     * @param  list<Binding>  $bindings
     */
    public function __construct(
        public string $httpMethod,
        public string $path,
        public string $controllerClass,
        public string $methodName,
        public int $status,
        public ?string $name,
        public array $bindings,
    ) {}

    /**
     * @return array{httpMethod: string, path: string, controllerClass: string, methodName: string, status: int, name: string|null, bindings: list<Binding>}
     */
    public function toArray(): array
    {
        return [
            'httpMethod' => $this->httpMethod,
            'path' => $this->path,
            'controllerClass' => $this->controllerClass,
            'methodName' => $this->methodName,
            'status' => $this->status,
            'name' => $this->name,
            'bindings' => $this->bindings,
        ];
    }

    /**
     * @param  array{httpMethod: string, path: string, controllerClass: string, methodName: string, status: int, name: string|null, bindings: list<Binding>}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['httpMethod'],
            $data['path'],
            $data['controllerClass'],
            $data['methodName'],
            $data['status'],
            $data['name'],
            $data['bindings'],
        );
    }
}
