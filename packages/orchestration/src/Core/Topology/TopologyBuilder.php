<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Core\Topology;

use Firefly\Orchestration\Core\Exception\TopologyValidationException;

/**
 * Layers a step graph by its dependsOn edges (Kahn's algorithm, jafly TopologyBuilder parity): layer N holds
 * every step whose dependencies all sit in layers < N, in DECLARATION order. The engines run the steps of a
 * layer one after another — PHP is synchronous — so the guarantee an application may rely on is the layer
 * guarantee (every dependency finished before a dependent starts), never parallelism.
 *
 * Step ids are strings even when they look numeric: PHP turns an array key '1' into int 1, so every id read
 * back from a key is cast, or a step called "1" would silently fail a strict comparison.
 */
final class TopologyBuilder
{
    /**
     * @param  array<array-key, list<string>>  $dependencies  step id => the ids it depends on, in declaration order;
     *                                                        a numeric id arrives as an int key
     * @return list<list<string>>
     */
    public static function layers(string $owner, array $dependencies): array
    {
        self::validate($owner, $dependencies);

        $remaining = $dependencies;
        $placed = [];
        $layers = [];
        while ($remaining !== []) {
            $layer = [];
            foreach ($remaining as $id => $dependsOn) {
                if (array_diff($dependsOn, array_map('strval', array_keys($placed))) === []) {
                    $layer[] = (string) $id;
                }
            }
            foreach ($layer as $id) {
                $placed[$id] = true;
                unset($remaining[$id]);
            }
            $layers[] = $layer;
        }

        return $layers;
    }

    /**
     * @param  array<array-key, list<string>>  $dependencies
     */
    public static function validate(string $owner, array $dependencies): void
    {
        if ($dependencies === []) {
            throw new TopologyValidationException("{$owner} declares no steps.");
        }

        foreach ($dependencies as $id => $dependsOn) {
            $id = (string) $id;
            foreach ($dependsOn as $dependency) {
                if ($dependency === $id) {
                    throw new TopologyValidationException("{$owner}: step [{$id}] depends on itself.");
                }
                if (! array_key_exists($dependency, $dependencies)) {
                    throw new TopologyValidationException("{$owner}: step [{$id}] depends on [{$dependency}], which is not a step of it.");
                }
            }
        }

        $state = [];
        foreach (array_keys($dependencies) as $id) {
            self::visit($owner, (string) $id, $dependencies, $state, []);
        }
    }

    /**
     * @param  array<array-key, list<string>>  $dependencies
     * @param  array<string, int>  $state  1 = on the current path, 2 = finished
     * @param  list<string>  $path
     */
    private static function visit(string $owner, string $id, array $dependencies, array &$state, array $path): void
    {
        $mark = $state[$id] ?? 0;
        if ($mark === 2) {
            return;
        }
        if ($mark === 1) {
            $start = array_search($id, $path, true);
            $cycle = [...array_slice($path, $start === false ? 0 : $start), $id];

            throw new TopologyValidationException("{$owner}: the steps form a cycle: ".implode(' -> ', $cycle).'.');
        }

        $state[$id] = 1;
        foreach ($dependencies[$id] ?? [] as $dependency) {
            self::visit($owner, $dependency, $dependencies, $state, [...$path, $id]);
        }
        $state[$id] = 2;
    }
}
