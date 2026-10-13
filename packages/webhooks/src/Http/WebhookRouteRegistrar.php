<?php

declare(strict_types=1);

namespace Firefly\Webhooks\Http;

use Firefly\Context\Boot\BootContext;
use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\BootPhase;
use Firefly\Webhooks\WebhooksSettings;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mounts POST {firefly.webhooks.path}/{provider} when webhooks are enabled (the FlagdSyncRouteRegistrar shape): no
 * middleware group, so no session and no CSRF token a provider could never send. With firefly/security's
 * HttpSecurity on, permit the path explicitly — the signature is the authentication. The provider segment accepts
 * exactly the characters a provider id may have.
 */
final class WebhookRouteRegistrar implements BootPass
{
    public const string ROUTE_NAME = 'firefly.webhooks.receive';

    public function phase(): BootPhase
    {
        return BootPhase::WiringPasses;
    }

    public function order(): int
    {
        return 56;
    }

    public function run(BootContext $context): void
    {
        $container = $context->container;
        if (! $container->bound(WebhooksSettings::class)) {
            return;
        }

        /** @var WebhooksSettings $settings */
        $settings = $container->make(WebhooksSettings::class);
        if (! $settings->enabled) {
            return;
        }

        /** @var Router $router */
        $router = $container->make('router');
        $router->post($settings->path.'/{provider}', static fn (Request $request, string $provider): Response => $container->make(WebhookController::class)($request, $provider))
            ->where('provider', '[A-Za-z0-9][A-Za-z0-9._-]*')
            ->name(self::ROUTE_NAME);
    }
}
