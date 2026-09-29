<?php

declare(strict_types=1);

namespace Firefly\Admin\Route;

use Firefly\Web\Route\RouteDescriptor;

/** @phpstan-import-type PropertyPlan from RouteDescriptor */
final readonly class RouteBodyNode
{
    /** @param list<self> $children */
    public function __construct(public string $name, public ?string $type, public bool $list, public array $children = [], public ?string $note = null) {}

    /** @return list<self> */
    public static function forBinding(RouteBinding $binding): array
    {
        $plan = $binding->plan;
        $type = $plan['type'];
        $shapes = $plan['dtos'] ?? [];
        if ($type === null || ! isset($shapes[$type])) {
            return array_map(static fn (string $name): self => new self($name, null, false), $plan['properties']);
        }
        $budget = 200;

        return self::walk($type, $shapes, [], $budget);
    }

    /**
     * @param  array<string, array<string, PropertyPlan>>  $shapes
     * @param  list<string>  $path
     * @return list<self>
     */
    private static function walk(string $type, array $shapes, array $path, int &$budget): array
    {
        $path[] = $type;
        $nodes = [];
        foreach ($shapes[$type] ?? [] as $name => $property) {
            if (--$budget < 0) {
                $nodes[] = new self('More properties', null, false, note: 'Display limit reached');
                break;
            }
            $class = $property['class'];
            $note = match (true) {
                $class !== null && in_array($class, $path, true) => 'Recursive reference',
                count($path) >= 16 => 'Depth limit reached',
                default => null,
            };
            $children = $class !== null && $note === null ? self::walk($class, $shapes, $path, $budget) : [];
            $nodes[] = new self($name, $class, $property['list'], $children, $note);
        }

        return $nodes;
    }
}
