<?php

declare(strict_types=1);

use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Observability\Scanner\ObservabilityMethodScanner;

it('refuses a metric attribute on a class with no stereotype', function (): void {
    (new ObservabilityMethodScanner)->scan(['Firefly\\Observability\\Tests\\Fixtures\\Unstereotyped' => __DIR__.'/../Fixtures/Unstereotyped']);
})->throws(ConfigurationException::class, 'carries no #[Component]-family stereotype');

it('refuses a metric attribute on a final class', function (): void {
    (new ObservabilityMethodScanner)->scan(['Firefly\\Observability\\Tests\\Fixtures\\FinalService' => __DIR__.'/../Fixtures/FinalService']);
})->throws(ConfigurationException::class, 'is final and a proxy must extend it');

it('refuses #[Timed(percentiles:)] and names the key that does publish percentiles', function (): void {
    (new ObservabilityMethodScanner)->scan(['Firefly\\Observability\\Tests\\Fixtures\\Percentiles' => __DIR__.'/../Fixtures/Percentiles']);
})->throws(ConfigurationException::class, 'firefly.observability.metrics.distribution.per-meter');
