<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Web;

use Firefly\Security\OAuth2\Server\Settings\AuthorizationServerSettings;
use Illuminate\Http\Request;

/**
 * The address table the filter consults: configured path => endpoint. Built by the auto-configuration from the
 * endpoint beans, so adding an endpoint is one more map entry there and nothing in the filter.
 */
final class OAuth2Endpoints
{
    /**
     * @param  array<string,OAuth2Endpoint>  $byPath
     */
    public function __construct(
        private readonly AuthorizationServerSettings $settings,
        private readonly array $byPath,
    ) {}

    public function match(Request $request): ?OAuth2Endpoint
    {
        foreach ($this->byPath as $path => $endpoint) {
            if ($this->settings->isEndpoint($request, $path)) {
                return $endpoint;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function paths(): array
    {
        return array_keys($this->byPath);
    }
}
