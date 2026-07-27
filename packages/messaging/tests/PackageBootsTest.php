<?php

declare(strict_types=1);

use Firefly\Context\Boot\ApplicationContext;
use Firefly\Messaging\MessagingServiceProvider;

it('boots green when discovered alongside the bootstrap provider', function () {
    $context = bootFireflyApp(['firefly' => []], [MessagingServiceProvider::class]);

    expect($context)->toBeInstanceOf(ApplicationContext::class);
});
