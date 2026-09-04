<?php

declare(strict_types=1);

namespace Firefly\Config\Tests\Fixtures;

/**
 * Pins the acronym handling of ReflectionConfigBinder's camelCase -> snake_case relaxation. A naive
 * "underscore before every capital" rule turns $apiURL into `api_u_r_l` and $HTTPProxyHost into
 * `_h_t_t_p_proxy_host`, neither of which any human would ever write in a config file; the binder's
 * two-pass regex produces `api_url` and `http_proxy_host` instead. Deliberately NOT annotated with
 * #[ConfigProperties] — it exists to be bound directly by the binder test, not to be discovered.
 */
final readonly class AcronymProperties
{
    public function __construct(
        public string $apiURL = 'unset',
        public string $HTTPProxyHost = 'unset',
    ) {}
}
