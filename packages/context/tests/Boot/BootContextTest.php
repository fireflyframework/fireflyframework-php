<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Config\Profile\Profiles;
use Firefly\Context\Boot\BootContext;
use Firefly\Context\Condition\ConditionEvaluationReport;
use Firefly\Context\Condition\ConditionEvaluator;
use Firefly\Context\Definition\BeanDefinitionRegistry;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;

it('threads container, definitions, config, profiles, conditions, and report through the boot pipeline', function () {
    $container = new Container;
    $definitions = new BeanDefinitionRegistry;
    $config = new Config(new Repository([]));
    $profiles = new Profiles([]);
    $conditions = new ConditionEvaluator($config, $profiles);
    $report = new ConditionEvaluationReport;

    $context = new BootContext($container, $definitions, $config, $profiles, $conditions, $report);

    expect($context->container)->toBe($container)
        ->and($context->definitions)->toBe($definitions)
        ->and($context->config)->toBe($config)
        ->and($context->profiles)->toBe($profiles)
        ->and($context->conditions)->toBe($conditions)
        ->and($context->report)->toBe($report);
});
