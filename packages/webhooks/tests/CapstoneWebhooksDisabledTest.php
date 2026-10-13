<?php

declare(strict_types=1);

use Firefly\Webhooks\Tests\Support\WebhooksDisabledCapstoneTestCase;
use Firefly\Webhooks\WebhookProcessor;

uses(WebhooksDisabledCapstoneTestCase::class);

it('mounts no endpoint and binds nothing while firefly.webhooks.enabled is false', function () {
    /** @var WebhooksDisabledCapstoneTestCase $this */
    $this->postWebhook('/webhooks/open', '{"type":"ping"}')->assertNotFound();

    expect($this->app()->bound(WebhookProcessor::class))->toBeFalse()
        ->and($this->events->published)->toBe([]);
});
