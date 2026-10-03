<?php

declare(strict_types=1);

use Firefly\FeatureFlags\Tests\Support\DisabledSyncServerTestCase;

uses(DisabledSyncServerTestCase::class);

it('does not mount the sync route when the server is disabled', function (): void {
    /** @var DisabledSyncServerTestCase $this */
    $this->get('/feature-flags/flagd.json', ['Authorization' => 'Bearer s3cret'])->assertNotFound();
});
