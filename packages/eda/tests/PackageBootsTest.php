<?php

declare(strict_types=1);

use Firefly\Context\Boot\ApplicationContext;
use Firefly\Eda\EdaServiceProvider;

it('boots green when discovered alongside the bootstrap provider', function () {
    $context = bootFireflyApp(['firefly' => []], [EdaServiceProvider::class]);

    expect($context)->toBeInstanceOf(ApplicationContext::class);
});
