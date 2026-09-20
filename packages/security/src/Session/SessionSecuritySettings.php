<?php

declare(strict_types=1);

namespace Firefly\Security\Session;

use Firefly\Config\Config;

/**
 * Whether the SecurityContext is carried by the Laravel session: on when `firefly.security.session.enabled`
 * says so, and IMPLIED by any mechanism that cannot work without it — form login, remember-me, and HTTP Basic
 * with `http_basic.session`. Read live from the Config port on every call, because the persistence filter
 * and the boot bootstrap both ask, and a test's withoutSecurity() flips the flags after boot.
 */
final class SessionSecuritySettings
{
    public function __construct(private readonly Config $config) {}

    public function enabled(): bool
    {
        return $this->config->bool('firefly.security.session.enabled', false)
            || $this->config->bool('firefly.security.form_login.enabled', false)
            || $this->config->bool('firefly.security.remember_me.enabled', false)
            || ($this->config->bool('firefly.security.http_basic.enabled', false) && $this->config->bool('firefly.security.http_basic.session', false));
    }

    /** Regenerate the session id on every interactive sign-in (session fixation protection). */
    public function fixationProtection(): bool
    {
        return $this->config->bool('firefly.security.session.fixation_protection', true);
    }
}
