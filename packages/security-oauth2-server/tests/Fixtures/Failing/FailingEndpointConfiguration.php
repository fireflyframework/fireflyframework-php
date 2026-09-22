<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Tests\Fixtures\Failing;

use Firefly\Container\Attributes\Bean;
use Firefly\Container\Attributes\Configuration;
use Firefly\Security\OAuth2\Server\Settings\AuthorizationServerSettings;
use Firefly\Security\OAuth2\Server\Web\OAuth2Endpoints;

/**
 * Supplies THE OAuth2Endpoints bean of a boot that scans this directory, the way an application overrides any
 * bean of the authorization server: a competing bean DEFINITION, which OAuth2ServerAutoConfiguration's
 * #[ConditionalOnMissingBean(OAuth2Endpoints)] backs off from because the ConditionEvaluator consults the
 * BeanDefinitionRegistry (a pre-boot $app->instance() would be invisible to the condition and overwritten at
 * FlushDefinitions — see firefly/security's Fixtures/Advice/AdviceSecurityConfiguration). The one endpoint it
 * maps, at the configured token path, throws; nothing else of the server is reachable, which is the point.
 */
#[Configuration]
final class FailingEndpointConfiguration
{
    #[Bean]
    public function oauth2Endpoints(AuthorizationServerSettings $settings): OAuth2Endpoints
    {
        return new OAuth2Endpoints($settings, [$settings->tokenEndpoint => new FailingTokenEndpoint]);
    }
}
