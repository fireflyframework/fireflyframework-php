<?php

declare(strict_types=1);

use Firefly\Context\Processor\BeanPostProcessor;
use Firefly\Data\DataServiceProvider;
use Firefly\Data\Transaction\TransactionalBeanPostProcessor;

/**
 * REAL-PROVIDER: the SHIPPED DataServiceProvider points at the COMMITTED manifests, the bootstrap provider
 * assembles them, and RegisterBeanPostProcessorsPass (phase 700) must discover the TransactionalBeanPostProcessor
 * as a BeanPostProcessor because its committed component row lists interfaces:[BeanPostProcessor::class].
 */
it('discovers the TransactionalBeanPostProcessor as a BeanPostProcessor via the shipped provider', function () {
    $context = bootFireflyApp(['firefly' => []], [DataServiceProvider::class]);
    $bpps = $context->getAll(BeanPostProcessor::class);

    expect(array_filter($bpps, static fn (object $b): bool => $b instanceof TransactionalBeanPostProcessor))
        ->not->toBeEmpty();
});
