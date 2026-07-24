<?php

declare(strict_types=1);

use Firefly\Kernel\Exception\Security\AuthenticationException;
use Firefly\Security\Authentication\ProviderManager;
use Firefly\Security\Core\Authentication;

it('throws when no provider supports the token', function () {
    (new ProviderManager([]))->authenticate(Authentication::unauthenticated('x', 'x', 'y'));
})->throws(AuthenticationException::class);
