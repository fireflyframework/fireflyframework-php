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
use Firefly\Context\Boot\BootPhase;
use Firefly\Context\Condition\ConditionEvaluationReport;
use Firefly\Context\Condition\ConditionEvaluator;
use Firefly\Context\Definition\BeanDefinitionRegistry;
use Firefly\Context\Definition\DefinitionSource;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;

function discoveryBootContext(BeanDefinitionRegistry $registry): BootContext
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

it('reports phase 200 and order 0', function () {
    $pass = new AutoConfigDiscoveryPass(new AutoConfigurationCollector, new DefinitionAssembler);
    expect($pass->phase())->toBe(BootPhase::AutoConfigDiscovery)
        ->and($pass->phase()->value)->toBe(200)
        ->and($pass->order())->toBe(0);
});

it('assembles each candidate as an AutoConfiguration-sourced definition and stashes it, WITHOUT writing the registry', function () {
    $componentPath = sys_get_temp_dir().'/disc-c-'.bin2hex(random_bytes(6)).'.php';
    $contextPath = sys_get_temp_dir().'/disc-x-'.bin2hex(random_bytes(6)).'.php';

    try {
        (new AutoConfigManifestCompiler)->write(
            ['Firefly\\AutoConfigure\\Tests\\CompileFixtures\\' => __DIR__.'/../CompileFixtures'],
            $componentPath, $contextPath,
        );

        $collector = new AutoConfigurationCollector;
        $collector->add(new AutoConfigurationCandidate('App\\SampleProvider', $componentPath, $contextPath));

        $registry = new BeanDefinitionRegistry;
        $pass = new AutoConfigDiscoveryPass($collector, new DefinitionAssembler);
        $pass->run(discoveryBootContext($registry));

        // Discovery stashed the assembled definitions...
        $assembled = $collector->assembledDefinitions();
        expect(array_map(fn ($d) => $d->class(), $assembled))->toContain(CompileSampleConfig::class)
            ->and($assembled[0]->source)->toBe(DefinitionSource::AutoConfiguration);

        // ...but wrote NOTHING to the registry (enum line 53).
        expect($registry->all())->toBe([]);
    } finally {
        @unlink($componentPath);
        @unlink($contextPath);
    }
});
