<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Boot;

use Closure;
use Firefly\Context\Boot\BootContext;
use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\BootPhase;
use Firefly\Cqrs\Handler\HandlerDescriptor;
use Firefly\Cqrs\Handler\HandlerKind;
use Firefly\Cqrs\Handler\HandlerManifest;
use Firefly\Cqrs\Handler\HandlerRegistry;
use Illuminate\Container\Container;

/**
 * Populates the HandlerRegistry from the app's compiled HandlerManifest at phase WiringPasses/1000 (the instance
 * stage). Runs in EVERY process — web request AND queue worker alike — so the share-nothing worker rebuilds the
 * identical registry from the same manifest (the M9 worker-reconstruction lesson). Each registered invoker resolves
 * its handler bean FRESH on every dispatch (never caching it at registration) — exactly like EventListenerWiringPass
 * / RegisterEventListenersPass — so the bus always observes the fully post-processed (possibly M8-#[Transactional]-
 * proxied) bean, giving cqrs command handlers transaction semantics for free. Reflection-free: keys come from the manifest.
 */
final class CqrsHandlerWiringPass implements BootPass
{
    public function phase(): BootPhase
    {
        return BootPhase::WiringPasses;
    }

    public function order(): int
    {
        return 0;
    }

    public function run(BootContext $context): void
    {
        $container = $context->container;

        /** @var HandlerManifest $manifest */
        $manifest = $container->make(HandlerManifest::class);
        /** @var HandlerRegistry $registry */
        $registry = $container->make(HandlerRegistry::class);

        foreach ($manifest->handlers() as $descriptor) {
            $invoker = $this->invoker($container, $descriptor);

            if ($descriptor->kind === HandlerKind::Command) {
                $registry->registerCommandHandler($descriptor->messageClass, $invoker);
            } else {
                $registry->registerQueryHandler($descriptor->messageClass, $invoker);
            }
        }
    }

    private function invoker(Container $container, HandlerDescriptor $descriptor): Closure
    {
        return static function (object $message) use ($container, $descriptor): mixed {
            $bean = $container->make($descriptor->handlerClass);
            $method = $descriptor->method;

            return $bean->{$method}($message);
        };
    }
}
