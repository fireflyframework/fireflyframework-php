<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Boot;

use Firefly\Container\Attributes\Component;
use Firefly\Container\Container as FireflyContainer;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
use Firefly\FeatureFlags\Registry\FlagRegistry;
use Firefly\Kernel\Lifecycle;
use OpenFeature\interfaces\flags\API;
use OpenFeature\interfaces\provider\Provider;
use OpenFeature\OpenFeatureAPI;
use WeakMap;

/** Starts the registry, installs this application's provider, and restores its predecessor on close. */
#[Component]
#[ConditionalOnProperty(name: 'firefly.feature-flags.enabled', havingValue: 'true')]
final class FeatureFlagsLifecycle implements Lifecycle
{
    /** @var WeakMap<self, bool>|null */
    private static ?WeakMap $active = null;

    private static int $nextSequence = 0;

    private ?Provider $installed = null;

    private ?Provider $previous = null;

    private int $sequence = 0;

    public function __construct(
        private readonly FireflyContainer $beans,
        private readonly ?API $api = null,
    ) {}

    public function start(): void
    {
        if ($this->beans->has(FlagRegistry::class)) {
            $registry = $this->beans->get(FlagRegistry::class);
            if ($registry instanceof FlagRegistry) {
                $registry->start();
            }
        }

        $provider = $this->beans->get(Provider::class);
        if ($provider instanceof Provider) {
            $api = $this->api();
            $this->previous = $api->getProvider();
            $api->setProvider($provider);
            $this->installed = $provider;
            $this->sequence = ++self::$nextSequence;
            self::$active ??= new WeakMap;
            self::$active[$this] = true;
        }
    }

    public function stop(): void
    {
        if ($this->installed !== null) {
            if ($this->ownsCurrentProvider() && $this->previous !== null) {
                $this->api()->setProvider($this->previous);
            } else {
                // A later application may still be running. Redirect its restore target past this stopped one.
                foreach (self::$active ?? [] as $lifecycle => $_) {
                    if ($lifecycle !== $this && $lifecycle->api() === $this->api() && $lifecycle->previous === $this->installed) {
                        $lifecycle->previous = $this->previous;
                    }
                }
            }
        }

        if (self::$active !== null) {
            unset(self::$active[$this]);
        }
        $this->installed = null;
        $this->previous = null;
    }

    private function api(): API
    {
        return $this->api ?? OpenFeatureAPI::getInstance();
    }

    private function ownsCurrentProvider(): bool
    {
        if ($this->api()->getProvider() !== $this->installed) {
            return false;
        }

        foreach (self::$active ?? [] as $lifecycle => $_) {
            if ($lifecycle !== $this && $lifecycle->api() === $this->api() && $lifecycle->installed === $this->installed && $lifecycle->sequence > $this->sequence) {
                return false;
            }
        }

        return true;
    }
}
