<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Client\Registration;

use Firefly\Config\Config;
use Firefly\Kernel\Exception\Framework\ConfigurationException;

/**
 * The two maps under `firefly.security.oauth2.client.*` as configured (Spring Boot's OAuth2ClientProperties):
 * `registration.{id}` and `provider.{id}`, each a map of settings, read raw — OAuth2ClientPropertiesMapper is
 * where they are interpreted and refused.
 */
final readonly class OAuth2ClientProperties
{
    /**
     * @param  array<string, array<string, mixed>>  $registrations
     * @param  array<string, array<string, mixed>>  $providers
     */
    public function __construct(
        public array $registrations = [],
        public array $providers = [],
    ) {}

    public static function fromConfig(Config $config): self
    {
        return new self(
            self::blocks($config, 'firefly.security.oauth2.client.registration'),
            self::blocks($config, 'firefly.security.oauth2.client.provider'),
        );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function blocks(Config $config, string $key): array
    {
        $blocks = [];
        foreach ($config->array($key, []) as $id => $block) {
            if (! is_string($id) || $id === '') {
                throw new ConfigurationException("{$key} must be a map keyed by id; found an entry with no id.");
            }
            if (! is_array($block)) {
                throw new ConfigurationException("{$key}.{$id} must be a map of settings.");
            }
            /** @var array<string, mixed> $block */
            $blocks[$id] = $block;
        }

        return $blocks;
    }
}
