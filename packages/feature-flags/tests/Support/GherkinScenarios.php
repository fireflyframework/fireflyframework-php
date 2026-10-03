<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Tests\Support;

/**
 * The subset of Gherkin the flagd-testbed evaluator suite uses — Feature/Background/Scenario/Scenario Outline,
 * tags on features, scenarios and Examples blocks, data tables appended to the step above them — expanded into
 * one step list per concrete scenario. A port of the probe runner that validated the suite against the
 * reference evaluator (125 passed, 15 skipped, all of them @fractional-v1).
 */
final class GherkinScenarios
{
    /** @var list<string> */
    public const array SKIPPED_TAGS = ['@fractional-v1'];

    /**
     * @return array{run: array<string, list<string>>, skipped: int}
     */
    public static function load(string $directory): array
    {
        $files = glob($directory.'/*.feature') ?: [];
        sort($files, SORT_STRING);

        $run = [];
        $skipped = 0;
        foreach ($files as $file) {
            foreach (self::parse((string) file_get_contents($file)) as [$name, $tags, $steps]) {
                if (array_intersect($tags, self::SKIPPED_TAGS) !== []) {
                    $skipped++;

                    continue;
                }
                $run[basename($file).': '.$name] = $steps;
            }
        }

        return ['run' => $run, 'skipped' => $skipped];
    }

    /**
     * Pest dataset: "file: scenario [row]" => [steps].
     *
     * @return array<string, array{0: list<string>}>
     */
    public static function dataset(string $directory): array
    {
        return array_map(static fn (array $steps): array => [$steps], self::load($directory)['run']);
    }

    /**
     * @return list<array{0: string, 1: list<string>, 2: list<string>}> name, tags, steps
     */
    private static function parse(string $feature): array
    {
        /** @var list<string> $featureTags */
        $featureTags = [];
        /** @var list<string> $pending */
        $pending = [];
        /** @var list<string> $background */
        $background = [];
        /** @var list<array{name: string, tags: list<string>, steps: list<string>, examples: list<array{tags: list<string>, rows: list<list<string>>}>}> $scenarios */
        $scenarios = [];
        /** @var array{name: string, tags: list<string>, steps: list<string>, examples: list<array{tags: list<string>, rows: list<list<string>>}>}|null $scenario */
        $scenario = null;
        /** @var array{tags: list<string>, rows: list<list<string>>}|null $examples */
        $examples = null;
        $mode = null;

        foreach (preg_split('/\R/', $feature) ?: [] as $line) {
            $raw = trim($line);
            if ($raw === '' || str_starts_with($raw, '#')) {
                continue;
            }
            if (str_starts_with($raw, '@')) {
                $pending = [...$pending, ...(preg_split('/\s+/', $raw) ?: [])];

                continue;
            }
            if (str_starts_with($raw, 'Feature:')) {
                $featureTags = $pending;
                $pending = [];

                continue;
            }
            if (str_starts_with($raw, 'Background:')) {
                $mode = 'background';

                continue;
            }
            if (str_starts_with($raw, 'Scenario')) {
                [$scenarios, $scenario, $examples] = self::close($scenarios, $scenario, $examples);
                $scenario = ['name' => $raw, 'tags' => array_values(array_unique([...$featureTags, ...$pending])), 'steps' => [], 'examples' => []];
                $pending = [];
                $mode = 'scenario';

                continue;
            }
            if ($scenario === null) {
                if ($mode === 'background') {
                    $background[] = $raw;
                }

                continue;
            }
            if (str_starts_with($raw, 'Examples')) {
                if ($examples !== null) {
                    $scenario['examples'][] = $examples;
                }
                $examples = ['tags' => $pending, 'rows' => []];
                $pending = [];
                $mode = 'examples';

                continue;
            }
            if (str_starts_with($raw, '|') && $mode === 'examples' && $examples !== null) {
                $examples['rows'][] = array_map(trim(...), explode('|', trim($raw, '|')));

                continue;
            }
            $last = array_key_last($scenario['steps']);
            if (str_starts_with($raw, '|') && $mode === 'scenario' && $last !== null) {
                $scenario['steps'][$last] .= "\n".$raw;

                continue;
            }
            if ($mode === 'scenario') {
                $scenario['steps'][] = $raw;
            }
        }
        [$scenarios] = self::close($scenarios, $scenario, $examples);

        $expanded = [];
        foreach ($scenarios as $outline) {
            if ($outline['examples'] === []) {
                $expanded[] = [$outline['name'], $outline['tags'], [...$background, ...$outline['steps']]];

                continue;
            }
            foreach ($outline['examples'] as $block) {
                $rows = $block['rows'];
                $header = array_shift($rows) ?? [];
                foreach ($rows as $row) {
                    $values = array_combine($header, $row);
                    $steps = array_map(
                        static fn (string $step): string => (string) preg_replace_callback('/<([^>]+)>/', static fn (array $m): string => $values[$m[1]] ?? $m[0], $step),
                        $outline['steps'],
                    );
                    $expanded[] = [
                        $outline['name'].' ['.implode(' | ', $row).']',
                        array_values(array_unique([...$outline['tags'], ...$block['tags']])),
                        [...$background, ...$steps],
                    ];
                }
            }
        }

        return $expanded;
    }

    /**
     * The scenario being read, with its last Examples block, appended to the list.
     *
     * @param  list<array{name: string, tags: list<string>, steps: list<string>, examples: list<array{tags: list<string>, rows: list<list<string>>}>}>  $scenarios
     * @param  array{name: string, tags: list<string>, steps: list<string>, examples: list<array{tags: list<string>, rows: list<list<string>>}>}|null  $scenario
     * @param  array{tags: list<string>, rows: list<list<string>>}|null  $examples
     * @return array{0: list<array{name: string, tags: list<string>, steps: list<string>, examples: list<array{tags: list<string>, rows: list<list<string>>}>}>, 1: null, 2: null}
     */
    private static function close(array $scenarios, ?array $scenario, ?array $examples): array
    {
        if ($scenario !== null) {
            if ($examples !== null) {
                $scenario['examples'][] = $examples;
            }
            $scenarios[] = $scenario;
        }

        return [$scenarios, null, null];
    }
}
