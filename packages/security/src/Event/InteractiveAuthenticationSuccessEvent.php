<?php

declare(strict_types=1);

namespace Firefly\Security\Event;

use Firefly\Security\Core\Authentication;

/**
 * A person signed in through a web mechanism — the form, HTTP Basic, a remember-me cookie or an OAuth2/OIDC
 * provider (`oauth2-login`, published by firefly/security-oauth2-client) (Spring's
 * InteractiveAuthenticationSuccessEvent, whose generatedBy is the filter class; here it is a stable string, so a
 * listener can branch on it without naming a framework class). Always preceded by the generic
 * AuthenticationSuccessEvent for the same token.
 */
final readonly class InteractiveAuthenticationSuccessEvent
{
    public const string FORM = 'form';

    public const string BASIC = 'basic';

    public const string REMEMBER_ME = 'remember-me';

    public const string OAUTH2_LOGIN = 'oauth2-login';

    public function __construct(
        public Authentication $authentication,
        public string $mechanism,
    ) {}
}
