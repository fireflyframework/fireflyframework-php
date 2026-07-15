<?php

declare(strict_types=1);

use Firefly\Context\Condition\Attributes\ConditionalOnClass;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
use Firefly\Context\Condition\ConditionEvaluationReport;
use Firefly\Context\Condition\ConditionOutcome;

it('records (class, attribute-FQCN, outcome) triples and exposes all()', function () {
    $report = new ConditionEvaluationReport;

    $report->record('App\CacheConfig', ConditionalOnProperty::class, ConditionOutcome::match('matched'));
    $report->record('App\OtherConfig', ConditionalOnClass::class, ConditionOutcome::noMatch('did not match'));

    expect($report->all())->toHaveCount(2)
        ->and($report->all()[0])->toBe([
            'class' => 'App\CacheConfig',
            'attribute' => ConditionalOnProperty::class,
            'outcome' => $report->all()[0]['outcome'],
        ]);
});

it('partitions entries into matches() and nonMatches()', function () {
    $report = new ConditionEvaluationReport;
    $matchOutcome = ConditionOutcome::match('matched');
    $noMatchOutcome = ConditionOutcome::noMatch('did not match');

    $report->record('App\CacheConfig', ConditionalOnProperty::class, $matchOutcome);
    $report->record('App\OtherConfig', ConditionalOnClass::class, $noMatchOutcome);

    expect($report->matches())->toHaveCount(1)
        ->and($report->matches()[0]['class'])->toBe('App\CacheConfig')
        ->and($report->matches()[0]['outcome'])->toBe($matchOutcome)
        ->and($report->nonMatches())->toHaveCount(1)
        ->and($report->nonMatches()[0]['class'])->toBe('App\OtherConfig')
        ->and($report->nonMatches()[0]['outcome'])->toBe($noMatchOutcome);
});

it('reasons recorded in the report name the actual observed value', function () {
    $report = new ConditionEvaluationReport;
    $report->record(
        'App\CacheConfig',
        ConditionalOnProperty::class,
        ConditionOutcome::noMatch("@ConditionalOnProperty (firefly.cache.enabled=false) did not match required value 'true'"),
    );

    expect($report->nonMatches()[0]['outcome']->reason)
        ->toBe("@ConditionalOnProperty (firefly.cache.enabled=false) did not match required value 'true'");
});
