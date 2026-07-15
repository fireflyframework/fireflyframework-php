<?php

declare(strict_types=1);

namespace Firefly\Context\Scanner;

use Firefly\Kernel\Exception\Framework\ConfigurationException;

final class ContextManifest
{
    /**
     * @param  list<ContextDescriptor>  $descriptors
     */
    public function __construct(public array $descriptors) {}

    /**
     * @param  array<int, array{class: string, postConstruct?: list<string>, preDestroy?: list<string>, listeners?: list<array{method: string, event: string, order: int}>, conditions?: list<array{type: string, args: list<mixed>}>, beanConditions?: list<array{method: string, conditions: list<array{type: string, args: list<mixed>}>}>}>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(array_map(
            static fn (array $row): ContextDescriptor => ContextDescriptor::fromArray($row),
            array_values($data),
        ));
    }

    public static function load(string $path): self
    {
        if (! is_file($path)) {
            throw new ConfigurationException("Context manifest not found at {$path}. Run the context scan first.");
        }

        /** @var mixed $data */
        $data = require $path;
        if (! is_array($data)) {
            throw new ConfigurationException("Context manifest at {$path} did not return an array.");
        }

        /** @var array<int, array{class: string, postConstruct?: list<string>, preDestroy?: list<string>, listeners?: list<array{method: string, event: string, order: int}>, conditions?: list<array{type: string, args: list<mixed>}>, beanConditions?: list<array{method: string, conditions: list<array{type: string, args: list<mixed>}>}>}> $data */
        return self::fromArray($data);
    }

    /**
     * Looks up the descriptor for one declared class. A linear scan is intentional and matches
     * M2/M3's manifests: the context manifest holds only classes with something to report (see
     * ContextScanner), so it stays small, and a lazily-built index would be complexity this class
     * does not need.
     */
    public function forClass(string $class): ?ContextDescriptor
    {
        foreach ($this->descriptors as $descriptor) {
            if ($descriptor->class === $class) {
                return $descriptor;
            }
        }

        return null;
    }
}
