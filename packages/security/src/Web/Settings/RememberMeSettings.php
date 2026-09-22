<?php

declare(strict_types=1);

namespace Firefly\Security\Web\Settings;

use Firefly\Config\Config;
use Firefly\Security\Jwt\JwtService;

/**
 * `firefly.security.remember_me.*`. The key signs every remember-me cookie, so it is held to the same rule
 * as the JWT secret — a placeholder or fewer than 32 bytes refuses to boot — and only when the mechanism is
 * on: an unused key may be anything.
 */
final readonly class RememberMeSettings
{
    public const int TWO_WEEKS = 1209600;

    public function __construct(
        public bool $enabled = false,
        public string $key = '',
        public string $parameter = 'remember-me',
        public string $cookieName = 'remember-me',
        public int $tokenValiditySeconds = self::TWO_WEEKS,
        public bool $alwaysRemember = false,
    ) {}

    public static function fromConfig(Config $config): self
    {
        $enabled = $config->bool('firefly.security.remember_me.enabled', false);
        $key = $config->string('firefly.security.remember_me.key', '');

        if ($enabled) {
            JwtService::assertStrongSecret($key, 'firefly.security.remember_me.key');
        }

        return new self(
            enabled: $enabled,
            key: $key,
            parameter: $config->string('firefly.security.remember_me.parameter', 'remember-me'),
            cookieName: $config->string('firefly.security.remember_me.cookie_name', 'remember-me'),
            tokenValiditySeconds: $config->int('firefly.security.remember_me.token_validity_seconds', self::TWO_WEEKS),
            alwaysRemember: $config->bool('firefly.security.remember_me.always_remember', false),
        );
    }
}
