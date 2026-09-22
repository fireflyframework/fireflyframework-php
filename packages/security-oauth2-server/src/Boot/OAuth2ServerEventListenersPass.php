<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Boot;

use Firefly\AutoConfigure\AutoConfigurationCandidate;
use Firefly\AutoConfigure\AutoConfigurationCollector;
use Firefly\Context\Boot\BootContext;
use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\BootPhase;
use Firefly\Context\Pass\RegisterEventListenersPass;
use Firefly\Context\Scanner\ContextManifest;
use Firefly\Security\OAuth2\Server\SecurityOAuth2ServerServiceProvider;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Registers this package's #[AsEventListener] methods against the dispatcher: for every class of the shipped
 * context manifest that declares one and whose conditions the context kept — bound in the container after
 * FlushDefinitions (650), which is the conditions' own verdict, so a listener is never wired for a bean the gates
 * removed — through RegisterEventListenersPass::registerListenersFor(), the exact guarded, per-dispatch-resolved
 * closure the phase-800 sweep builds, called from a second site as that method's docblock invites.
 *
 * WHY A PASS AT ALL: RegisterEventListenersPass reads BootContext::$contextManifest, which is the APPLICATION's
 * manifest — the scanned or `firefly:cache`d one. An auto-configuration package's manifest is loaded at phase
 * 200 (AutoConfigDiscoveryPass) for its #[ConditionalOn*] metadata alone, assembled into definitions and let go;
 * it never reaches the sweep. A package's #[AsEventListener] therefore compiles into the manifest, passes the
 * freshness guard, and is silent at runtime — the same gap DomainEventBridgeWiringPass closes for firefly/cqrs
 * with a hand-built listener. Here the listener is SessionAuthenticationTimeListener, which needs
 * InteractiveAuthenticationSuccessEvent to stamp the sign-in instant `auth_time` and `max_age` are measured
 * from (and to tell a remember-me cookie's sign-in, which is no active authentication, from the form's);
 * without this pass a browser signed in hours ago would satisfy `max_age=60` on its first authorization
 * request.
 *
 * WiringPasses/220, after OAuth2ServerWiringPass's refusals (210); a no-op while the server is off, so the
 * manifest is not even loaded for an application that installed the package and left it off. The manifest is
 * the one SecurityOAuth2ServerServiceProvider recorded in the AutoConfigurationCollector — never a second copy
 * of its path — and a candidate whose manifests are not compiled yet contributed no definitions at phase 200,
 * so there is nothing to wire and nothing to refuse.
 */
final class OAuth2ServerEventListenersPass implements BootPass
{
    public function phase(): BootPhase
    {
        return BootPhase::WiringPasses;
    }

    public function order(): int
    {
        return 220;
    }

    public function run(BootContext $context): void
    {
        if (! $context->config->bool('firefly.security.oauth2.server.enabled', false)) {
            return;
        }

        $container = $context->container;
        $candidate = self::candidate($container->bound(AutoConfigurationCollector::class) ? $container->make(AutoConfigurationCollector::class) : null);
        if ($candidate === null || ! is_file($candidate->contextManifestPath)) {
            return;
        }

        $manifest = ContextManifest::load($candidate->contextManifestPath);

        /** @var Dispatcher $dispatcher */
        $dispatcher = $container->make('events');

        foreach ($manifest->descriptors as $descriptor) {
            if ($descriptor->listeners === [] || ! $container->bound($descriptor->class)) {
                continue;
            }

            RegisterEventListenersPass::registerListenersFor($dispatcher, $manifest, $container, $descriptor->class, $descriptor->class);
        }
    }

    private static function candidate(?AutoConfigurationCollector $collector): ?AutoConfigurationCandidate
    {
        if ($collector === null) {
            return null;
        }

        foreach ($collector->all() as $candidate) {
            if ($candidate->provider === SecurityOAuth2ServerServiceProvider::class) {
                return $candidate;
            }
        }

        return null;
    }
}
