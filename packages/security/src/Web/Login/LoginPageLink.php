<?php

declare(strict_types=1);

namespace Firefly\Security\Web\Login;

/** One "Sign in with {label}" button on the login page: a stable id (a data attribute), the label, the URL. */
final readonly class LoginPageLink
{
    public function __construct(
        public string $id,
        public string $label,
        public string $url,
    ) {}
}
