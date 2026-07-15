<?php

declare(strict_types=1);

namespace Firefly\Container\Scanner;

use Firefly\Container\Descriptor\ComponentDescriptor;
use Firefly\Kernel\Exception\Framework\ConfigurationException;

final class ComponentManifest
{
    /**
     * @param  list<ComponentDescriptor>  $components
     */
    public function __construct(public array $components) {}

    /**
     * @param  array<int, array{class: string, stereotype: string, name: string|null, scope: string, primary: bool, order: int, qualifier: string|null, interfaces: list<class-string>, beans: list<array{method: string, returns: string, name: string|null, scope: string, primary: bool, order: int, lazy?: bool}>, lazy?: bool}>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(array_map(
            static fn (array $row): ComponentDescriptor => ComponentDescriptor::fromArray($row),
            array_values($data),
        ));
    }

    public static function load(string $path): self
    {
        if (! is_file($path)) {
            throw new ConfigurationException("Component manifest not found at {$path}. Run the component scan first.");
        }

        /** @var mixed $data */
        $data = require $path;
        if (! is_array($data)) {
            throw new ConfigurationException("Component manifest at {$path} did not return an array.");
        }

        /** @var array<int, array{class: string, stereotype: string, name: string|null, scope: string, primary: bool, order: int, qualifier: string|null, interfaces: list<class-string>, beans: list<array{method: string, returns: string, name: string|null, scope: string, primary: bool, order: int, lazy?: bool}>, lazy?: bool}> $data */
        return self::fromArray($data);
    }
}
