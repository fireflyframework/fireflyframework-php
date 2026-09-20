<?php

declare(strict_types=1);

namespace Firefly\Security\Web\Settings;

use Firefly\Config\Config;

/**
 * `firefly.security.http_basic.*`. `session` decides whether a successful Basic authentication is stored in
 * the session (a browser then sends the header once) or is re-verified on every request (stateless, the
 * default and the right choice for an API client).
 */
final readonly class HttpBasicSettings
{
    public function __construct(
        public bool $enabled = false,
        public string $realm = 'LaraFly',
        public bool $session = false,
    ) {}

    public static function fromConfig(Config $config): self
    {
        return new self(
            enabled: $config->bool('firefly.security.http_basic.enabled', false),
            realm: $config->string('firefly.security.http_basic.realm', 'LaraFly'),
            session: $config->bool('firefly.security.http_basic.session', false),
        );
    }

    /** The WWW-Authenticate value (RFC 7617): the realm quoted with its quotes and backslashes escaped. */
    public function challenge(): string
    {
        return 'Basic realm="'.addcslashes($this->realm, '"\\').'", charset="UTF-8"';
    }
}
