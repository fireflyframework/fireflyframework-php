<?php

declare(strict_types=1);

use Firefly\Security\Tests\Support\SecuredHealthCapstoneTestCase;

uses(SecuredHealthCapstoneTestCase::class);

it('withholds health components from an anonymous scrape', function () {
    /** @var SecuredHealthCapstoneTestCase $this */
    $body = $this->getJson('/actuator/health')->assertOk()->json();

    expect($body)->toHaveKey('status')->not->toHaveKey('components');
});

it('publishes them to a principal holding the configured role', function () {
    /** @var SecuredHealthCapstoneTestCase $this */
    $body = $this->signIn(['ROLE_ACTUATOR'])->getJson('/actuator/health')->assertOk()->json();

    expect($body)->toHaveKey('components');
});

it('withholds them from an authenticated principal without the role', function () {
    /** @var SecuredHealthCapstoneTestCase $this */
    $body = $this->signIn(['ROLE_USER'])->getJson('/actuator/health')->assertOk()->json();

    expect($body)->toHaveKey('status')->not->toHaveKey('components');
});

it('publishes them to a principal whose role implies the configured one', function () {
    /** @var SecuredHealthCapstoneTestCase $this */
    $body = $this->signIn(['ROLE_ADMIN'])->getJson('/actuator/health')->assertOk()->json();

    expect($body)->toHaveKey('components');
});
