<?php

declare(strict_types=1);

use Firefly\AutoConfigure\Assembly\AutoConfigManifestCompiler;
use Firefly\AutoConfigure\Assembly\DefinitionAssembler;
use Firefly\AutoConfigure\AutoConfigurationCandidate;
use Firefly\AutoConfigure\AutoConfigurationCollector;
use Firefly\AutoConfigure\Pass\AutoConfigDiscoveryPass;
use Firefly\AutoConfigure\Tests\CompileFixtures\CompileSampleConfig;
use Firefly\Config\Config;
use Firefly\Config\Profile\Profiles;
use Firefly\Context\Boot\BootContext;
use Firefly\Context\Condition\ConditionEvaluationReport;
use Firefly\Context\Condition\ConditionEvaluator;
use Firefly\Context\Definition\BeanDefinitionRegistry;
use Firefly\Context\Definition\DefinitionSource;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;

/**
 * Builds a bare BootContext for the discovery pass. Self-contained (no cross-file helper) so this file also
 * runs in isolation — the fault-injection step drives exactly this test.
 */
function gracefulSkipBootContext(BeanDefinitionRegistry $registry): BootContext
{
    $config = new Config(new Repository([]));
    $profiles = new Profiles([]);

    return new BootContext(
        container: new Container,
        definitions: $registry,
        config: $config,
        profiles: $profiles,
        conditions: new ConditionEvaluator($config, $profiles),
        report: new ConditionEvaluationReport,
    );
}

it('skips a candidate whose manifests are absent while still processing one whose manifests exist', function () {
    // A PRESENT candidate: real manifests written by the compiler.
    $componentPath = sys_get_temp_dir().'/disc-present-c-'.bin2hex(random_bytes(6)).'.php';
    $contextPath = sys_get_temp_dir().'/disc-present-x-'.bin2hex(random_bytes(6)).'.php';

    // An ABSENT candidate (e.g. installed before `firefly:cache` compiled it): paths that do NOT exist.
    $missingComponent = sys_get_temp_dir().'/disc-missing-c-'.bin2hex(random_bytes(6)).'.php';
    $missingContext = sys_get_temp_dir().'/disc-missing-x-'.bin2hex(random_bytes(6)).'.php';

    try {
        (new AutoConfigManifestCompiler)->write(
            ['Firefly\\AutoConfigure\\Tests\\CompileFixtures\\' => __DIR__.'/../CompileFixtures'],
            $componentPath, $contextPath,
        );

        $collector = new AutoConfigurationCollector;
        $collector->add(new AutoConfigurationCandidate('App\\UncompiledProvider', $missingComponent, $missingContext));
        $collector->add(new AutoConfigurationCandidate('App\\SampleProvider', $componentPath, $contextPath));

        $registry = new BeanDefinitionRegistry;
        $pass = new AutoConfigDiscoveryPass($collector, new DefinitionAssembler);

        // Runs WITHOUT throwing — the uncompiled candidate is a no-op, not a boot crash...
        $pass->run(gracefulSkipBootContext($registry));

        // ...and ONLY the present candidate's definition was stashed (nothing for the absent one).
        $assembled = $collector->assembledDefinitions();
        expect(array_map(fn ($d) => $d->class(), $assembled))->toBe([CompileSampleConfig::class])
            ->and($assembled[0]->source)->toBe(DefinitionSource::AutoConfiguration);
    } finally {
        @unlink($componentPath);
        @unlink($contextPath);
    }
});
