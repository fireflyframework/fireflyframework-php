<?php

declare(strict_types=1);

use Firefly\Resilience\ResilienceServiceProvider;
use Firefly\Security\OAuth2\Server\Tests\Support\OAuth2ServerCapstoneTestCase;

abstract class RateLimitedCapstoneTestCase extends OAuth2ServerCapstoneTestCase
{
    protected function fireflyProviders(): array
    {
        return [ResilienceServiceProvider::class, ...parent::fireflyProviders()];
    }

    protected function serverOverrides(): array
    {
        return [
            'cache.default' => 'array',
            'firefly.security.oauth2.server.rate_limit.enabled' => true,
            'firefly.security.oauth2.server.rate_limit.max_tokens' => 2,
            'firefly.security.oauth2.server.rate_limit.refill_rate' => 0.0001,
        ];
    }
}

uses(RateLimitedCapstoneTestCase::class);

it('answers 429 temporarily_unavailable with Retry-After once a client exhausts its bucket, per client', function () {
    /** @var RateLimitedCapstoneTestCase $this */
    $this->oauth2()->clientCredentials('svc', OAuth2ServerCapstoneTestCase::SVC_SECRET)->assertOk();
    $this->oauth2()->clientCredentials('svc', OAuth2ServerCapstoneTestCase::SVC_SECRET)->assertOk();
    $this->oauth2()->clientCredentials('svc', OAuth2ServerCapstoneTestCase::SVC_SECRET)
        ->assertStatus(429)
        ->assertHeader('Retry-After', '1')
        ->assertJson(['error' => 'temporarily_unavailable']);

    // Another client has its own bucket; a wrong secret also spends from the presented id's bucket.
    $this->oauth2()->clientCredentials('web-app', OAuth2ServerCapstoneTestCase::WEB_APP_SECRET)->assertOk();
    $this->oauth2()->clientCredentials('web-app', 'nope')->assertStatus(401);
    $this->oauth2()->clientCredentials('web-app', 'nope')->assertStatus(429);
});
