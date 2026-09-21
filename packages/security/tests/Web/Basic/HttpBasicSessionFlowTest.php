<?php

declare(strict_types=1);

use Firefly\Security\Core\SecurityContextHolder;
use Firefly\Security\Event\InteractiveAuthenticationSuccessEvent;
use Firefly\Security\Tests\Support\SecurityCapstoneTestCase;
use Firefly\Security\Tests\Support\SecurityFlows;

/**
 * HTTP Basic with `http_basic.session` on, through the real pipeline: the key implies session security, so
 * the cookie/session middleware is on the global stack, a success is stored like a form login with the id
 * regenerated, and the next request is answered from the session alone — the header is verified ONCE.
 */
abstract class BasicSessionCapstoneTestCase extends SecurityCapstoneTestCase
{
    protected function securityOverrides(): array
    {
        return [
            'firefly.security.http_basic.enabled' => true,
            'firefly.security.http_basic.session' => true,
        ];
    }
}

uses(BasicSessionCapstoneTestCase::class, SecurityFlows::class);

it('stores a Basic sign-in in the session with a regenerated id, so the following requests need no header', function () {
    /** @var BasicSessionCapstoneTestCase $this */
    // 1. An anonymous session exists before the sign-in: the id an attacker could have planted.
    $anonymous = $this->getJson('/open');
    $anonymous->assertOk();
    $before = (string) $anonymous->getCookie($this->sessionCookieName())?->getValue();
    expect($before)->not->toBe('');

    // 2. The header, in that same session: 200, and a DIFFERENT session id on the way out.
    $this->forgetSession();
    $signedIn = $this->followSession($anonymous)
        ->withHeader('Authorization', 'Basic '.base64_encode('ada:secret'))
        ->getJson('/api/orders');
    $signedIn->assertOk()->assertJson(['orders' => ['order-1']]);
    $after = (string) $signedIn->getCookie($this->sessionCookieName())?->getValue();

    expect($after)->not->toBe('')
        ->and($after)->not->toBe($before)
        ->and($this->events->interactive())->toHaveCount(1)
        ->and($this->events->interactive()[0]->mechanism)->toBe(InteractiveAuthenticationSuccessEvent::BASIC)
        ->and($this->events->interactive()[0]->authentication->getName())->toBe('ada')
        ->and(SecurityContextHolder::getContext()->isAuthenticated())->toBeFalse();

    // 3. The session cookie alone, no header: the persistence filter loads ada, and Basic is not consulted.
    $this->flushHeaders();
    $this->forgetSession();
    $this->followSession($signedIn)->getJson('/whoami')->assertOk()->assertJson(['name' => 'ada', 'authorities' => ['ROLE_USER']]);
    $this->followSession($signedIn)->getJson('/api/orders')->assertOk();

    // 4. A browser that keeps sending the header is not re-verified: still one interactive sign-in.
    $this->forgetSession();
    $this->followSession($signedIn)->withHeader('Authorization', 'Basic '.base64_encode('ada:secret'))->getJson('/whoami')->assertJson(['name' => 'ada']);
    expect($this->events->interactive())->toHaveCount(1)
        ->and($this->events->successes())->toHaveCount(1);

    // 5. The OLD id names nothing: the planted cookie does not ride the sign-in.
    $this->flushHeaders();
    $this->forgetSession();
    $this->forgetCookies();
    $this->withCredentials()->withCookie($this->sessionCookieName(), $before)->getJson('/whoami')->assertStatus(401);
});

it('leaves a failed header out of the session and answers the challenge', function () {
    /** @var BasicSessionCapstoneTestCase $this */
    $refused = $this->withHeader('Authorization', 'Basic '.base64_encode('ada:wrong'))->getJson('/api/orders');
    $refused->assertStatus(401)->assertHeader('WWW-Authenticate', 'Basic realm="LaraFly", charset="UTF-8"');

    $this->flushHeaders();
    $this->forgetSession();
    $this->followSession($refused)->getJson('/whoami')->assertStatus(401);

    expect($this->events->failures())->toHaveCount(1)
        ->and($this->events->failures()[0]->username)->toBe('ada')
        ->and($this->events->interactive())->toBe([]);
});
