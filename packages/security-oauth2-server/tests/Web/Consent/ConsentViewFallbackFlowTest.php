<?php

declare(strict_types=1);

use Firefly\Security\OAuth2\Server\Tests\Support\OAuth2ServerCapstoneTestCase;
use Firefly\Security\Tests\Support\RecordingLogger;
use Illuminate\Foundation\Application;
use Psr\Log\LoggerInterface;

abstract class ConsentViewFallbackCapstoneTestCase extends OAuth2ServerCapstoneTestCase
{
    public RecordingLogger $logger;

    protected function serverOverrides(): array
    {
        return ['firefly.security.oauth2.server.consent.view' => 'firefly-oauth2-tests::missing'];
    }

    protected function defineFireflyEnvironment(Application $app): void
    {
        parent::defineFireflyEnvironment($app);

        $this->logger = new RecordingLogger;
        $app->instance(LoggerInterface::class, $this->logger);
    }
}

uses(ConsentViewFallbackCapstoneTestCase::class);

it('falls back to the framework page, logging the view name at warning, when the configured view does not exist', function () {
    /** @var ConsentViewFallbackCapstoneTestCase $this */
    $this->signIn();

    $this->oauth2()->authorize('web-app', OAuth2ServerCapstoneTestCase::REDIRECT_URI, 'openid')->assertOk()->assertSee('Allow access');

    $warnings = $this->logger->mentioning('consent view');
    expect($warnings)->toHaveCount(1)
        ->and($warnings[0]['level'])->toBe('warning')
        ->and($warnings[0]['message'])->toContain('firefly-oauth2-tests::missing');
});
