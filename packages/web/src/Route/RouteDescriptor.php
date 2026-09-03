<?php

declare(strict_types=1);

namespace Firefly\Web\Route;

/**
 * A single compiled route: the HTTP method, full path, controller/method, default status, optional Laravel
 * route name, whether the declaring class is the HTML stereotype, and a pure-array binding plan (no
 * closures/objects) so it var_exports cleanly.
 *
 * `html` records that the route was declared by a #[Controller] rather than a #[RestController]. The
 * stereotype is an ATTRIBUTE, so answering the question needs reflection — which is why it is answered once
 * at scan time and compiled, rather than asked again by anything downstream. firefly/openapi is the first
 * consumer: an HTML page is part of the application's HTTP surface but it is not a JSON API operation, and
 * documenting it as `application/json` would generate a typed client for a response that is a web page.
 * Defaults to false so a manifest compiled before this field existed still loads.
 *
 * `dtos` is optional and present only on a body binding whose DTO actually nests: a table keyed by class,
 * each row mapping a constructor parameter to the class it is built from (null for a builtin) and whether
 * the payload holds a LIST of that class. RouteScanner compiles it; ArgumentResolver hydrates from it
 * without reflection. Optional so a plan for a flat DTO — and a manifest compiled before the scanner emitted
 * the key — stays exactly as it was.
 *
 * @phpstan-type PropertyPlan array{class: string|null, list: bool}
 * @phpstan-type Binding array{name: string, kind: string, key: string, type: string|null, required: bool, default: mixed, valid: bool, properties: list<string>, dtos?: array<string, array<string, PropertyPlan>>}
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
        public bool $html = false,
    ) {}

    /**
     * @return array{httpMethod: string, path: string, controllerClass: string, methodName: string, status: int, name: string|null, bindings: list<Binding>, html: bool}
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
            'html' => $this->html,
        ];
    }

    /**
     * @param  array{httpMethod: string, path: string, controllerClass: string, methodName: string, status: int, name: string|null, bindings: list<Binding>, html?: bool}  $data
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
            $data['html'] ?? false,
        );
    }
}
