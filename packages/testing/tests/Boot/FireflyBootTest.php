<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
use Firefly\Context\Condition\ConditionEvaluator;
use Firefly\Scheduling\Schedule\ScheduledManifest;
use Firefly\Testing\Boot\FireflyBoot;
use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;

it('builds an arity-2 ConditionEvaluator with a default empty Profiles', function () {
    $config = new Config(new Repository(['firefly' => ['demo' => ['enabled' => true]]]));
    $evaluator = FireflyBoot::makeConditionEvaluator($config);

    expect($evaluator)->toBeInstanceOf(ConditionEvaluator::class)
        ->and($evaluator->evaluate(new ConditionalOnProperty('firefly.demo.enabled'))->matched)->toBeTrue();
});

it('binds an empty ScheduledManifest stub', function () {
    $app = new Application;
    $manifest = FireflyBoot::stubScheduledManifest($app);

    expect($manifest)->toBeInstanceOf(ScheduledManifest::class)
        ->and($app->make(ScheduledManifest::class))->toBe($manifest);
});
