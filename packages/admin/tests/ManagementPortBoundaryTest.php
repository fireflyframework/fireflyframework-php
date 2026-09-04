<?php

declare(strict_types=1);

use Firefly\Admin\Tests\Support\ManagementPortTestCase;

uses(ManagementPortTestCase::class);

/**
 * The management port boundary applies to the dashboard MORE than to the JSON actuator, not less.
 *
 * The actuator withholds sensitive endpoints behind ExposureModel; the dashboard deliberately bypasses that
 * model so it can render beans, env and config properties in-process. A dashboard still answering on the
 * public application port after an operator moved management traffic to a private one would publish exactly
 * the surface they moved, with no signal that it had happened.
 *
 * The counter-case — that nothing changes when no management port is configured — is the whole of
 * CapstoneAdminIntegrationTest, which configures none and expects 200s throughout.
 */
it('refuses every dashboard page that did not arrive on the management port', function (string $path) {
    /** @var ManagementPortTestCase $this */
    $this->get($path)->assertStatus(404);
})->with(['/firefly', '/firefly/beans', '/firefly/env', '/firefly/configprops', '/firefly/graph']);

// 404, never 403: a 403 confirms a management surface exists on some other port, which is one more fact than
// an unauthenticated scan of the public port deserves.
it('does not confirm that a management surface exists elsewhere', function () {
    /** @var ManagementPortTestCase $this */
    $response = $this->get('/firefly/env');

    $response->assertStatus(404);

    expect($response->getContent())->not->toContain('9001')
        ->and($response->getContent())->not->toContain('management');
});
