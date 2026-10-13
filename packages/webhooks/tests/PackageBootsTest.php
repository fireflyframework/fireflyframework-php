<?php

declare(strict_types=1);

use Firefly\Context\Boot\ApplicationContext;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Webhooks\WebhooksServiceProvider;
use Firefly\Webhooks\WebhooksSettings;
use Firefly\Webhooks\WebhooksWiringProvider;

it('boots inert when firefly.webhooks.enabled is off', function () {
    $app = fireflyApplication(config: ['firefly' => []], providers: [WebhooksServiceProvider::class, WebhooksWiringProvider::class]);

    expect($app->make(ApplicationContext::class))->toBeInstanceOf(ApplicationContext::class)
        ->and($app->bound(WebhooksSettings::class))->toBeFalse();
});

it('refuses the boot when an enabled provider has no secret', function () {
    expect(fn () => fireflyApplication(
        config: ['firefly' => ['webhooks' => ['enabled' => true, 'providers' => ['stripe' => ['validator' => 'stripe']]]]],
        providers: [WebhooksServiceProvider::class, WebhooksWiringProvider::class],
    ))->toThrow(ConfigurationException::class, 'firefly.webhooks.providers.stripe.secret');
});
