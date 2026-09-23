<?php

declare(strict_types=1);

use Firefly\Actuator\Health\DenyHealthDetailsAuthorizer;
use Firefly\Actuator\Health\HealthDetailsAuthorizer;
use Firefly\Context\Boot\ApplicationContext;
use Firefly\Security\Tests\Support\SecurityOffHealthCapstoneTestCase;

uses(SecurityOffHealthCapstoneTestCase::class);

it('leaves actuator its deny-by-default authorizer when the security master flag is off', function () {
    /** @var SecurityOffHealthCapstoneTestCase $this */
    /** @var ApplicationContext $context */
    $context = $this->app()->make(ApplicationContext::class);

    // Not merely "refuses" — WHICH bean is bound. PrincipalHealthDetailsAuthorizer checks the master flag
    // itself and would also answer false here, so an assertion on the body alone cannot tell a conditional
    // that fired from one that was never needed, and it is the conditional the upgrade note promises.
    expect($context->get(HealthDetailsAuthorizer::class))->toBeInstanceOf(DenyHealthDetailsAuthorizer::class);
});

it('withholds the component details it would disclose with the flag on', function () {
    /** @var SecurityOffHealthCapstoneTestCase $this */
    $body = $this->getJson('/actuator/health')->assertOk()->json();

    // Same `show-details: when-authorized`, same empty roles list that admits every authenticated principal
    // in SecuredHealthCapstoneTest — and no components, because nothing filled the port.
    expect($body)->toHaveKey('status')->not->toHaveKey('components');
});
