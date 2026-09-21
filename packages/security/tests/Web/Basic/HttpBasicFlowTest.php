<?php

declare(strict_types=1);

use Firefly\Security\Tests\Support\SecurityCapstoneTestCase;
use Firefly\Security\Tests\Support\SecurityFlows;

abstract class BasicCapstoneTestCase extends SecurityCapstoneTestCase
{
    protected function securityOverrides(): array
    {
        return ['firefly.security.http_basic.enabled' => true];
    }
}

uses(BasicCapstoneTestCase::class, SecurityFlows::class);

it('authenticates an API request per header, challenges without one, and keeps nothing between requests', function () {
    /** @var BasicCapstoneTestCase $this */
    $this->getJson('/api/orders')
        ->assertStatus(401)
        ->assertHeader('WWW-Authenticate', 'Basic realm="LaraFly", charset="UTF-8"')
        ->assertJson(['code' => 'AUTHENTICATION_FAILED']);

    $this->withHeader('Authorization', 'Basic '.base64_encode('ada:secret'))->getJson('/api/orders')
        ->assertOk()
        ->assertJson(['orders' => ['order-1']]);

    $this->withHeader('Authorization', 'Basic '.base64_encode('ada:secret'))->getJson('/whoami')
        ->assertJson(['name' => 'ada', 'authorities' => ['ROLE_USER']]);

    // Stateless: the next request without the header is anonymous again (session security is off in this
    // suite, so nothing was stored). withHeader() persists for the test, hence the flush.
    $this->flushHeaders();
    $this->getJson('/whoami')->assertStatus(401);

    $this->withHeader('Authorization', 'Basic '.base64_encode('ada:wrong'))->getJson('/api/orders')
        ->assertStatus(401)
        ->assertHeader('WWW-Authenticate', 'Basic realm="LaraFly", charset="UTF-8"');

    expect($this->events->interactive())->toHaveCount(2)
        ->and($this->events->failures())->toHaveCount(1)
        ->and($this->events->failures()[0]->username)->toBe('ada');
});

it('answers a browser with the 401 page plus the challenge, since no login form is on', function () {
    /** @var BasicCapstoneTestCase $this */
    $this->get('/home')
        ->assertStatus(401)
        ->assertHeader('WWW-Authenticate', 'Basic realm="LaraFly", charset="UTF-8"')
        ->assertSee('401');
});
