<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Registry;

use Firefly\FeatureFlags\Definition\FlagDocument;

/**
 * Per-key composition (spec §4.5): the highest layer that defines a key supplies its WHOLE definition (no deep
 * merge); `$evaluators` and document metadata merge per name with the same precedence.
 */
final class Composer
{
    /**
     * @param  list<array{0: string, 1: FlagDocument}>  $layers  source name and document, lowest precedence first
     */
    public static function compose(array $layers): Composition
    {
        $byKey = [];
        $evaluators = [];
        $metadata = [];

        foreach ($layers as [$source, $document]) {
            foreach ($document->flags as $definition) {
                $byKey[$definition->key][] = ['source' => $source, 'definition' => $definition];
            }
            foreach ($document->evaluators as $name => $rule) {
                $evaluators[$name] = $rule;
            }
            $metadata = array_replace($metadata, $document->metadata);
        }

        $flags = [];
        foreach ($byKey as $key => $layersOfKey) {
            $winner = $layersOfKey[array_key_last($layersOfKey)];
            $flags[(string) $key] = new ComposedFlag(
                $winner['definition'],
                $winner['source'],
                array_map(static fn (array $layer): string => $layer['source'], array_slice($layersOfKey, 0, -1)),
                $layersOfKey,
            );
        }
        ksort($flags, SORT_STRING);

        return new Composition($flags, $evaluators, $metadata);
    }
}
