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
use Throwable;
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

    private ?self $previousOwner = null;

    private int $sequence = 0;

    public function __construct(
        private readonly FireflyContainer $beans,
        private readonly ?API $api = null,
    ) {}

    public function start(): void
    {
        $provider = $this->beans->get(Provider::class);
        if ($provider instanceof Provider) {
            $api = $this->api();
            $this->previous = $api->getProvider();
            $this->previousOwner = $this->activeOwner($this->previous);
            $this->installed = $provider;
            $this->sequence = ++self::$nextSequence;
            self::$active ??= new WeakMap;
            self::$active[$this] = true;
        }

        try {
            if ($this->beans->has(FlagRegistry::class)) {
                $registry = $this->beans->get(FlagRegistry::class);
                if ($registry instanceof FlagRegistry) {
                    $registry->start();
                }
            }

            if ($provider instanceof Provider) {
                $this->api()->setProvider($provider);
            }
        } catch (Throwable $failure) {
            $this->stop();
            throw $failure;
        }
    }

    public function stop(): void
    {
        if ($this->installed !== null) {
            if ($this->ownsCurrentProvider() && $this->previous !== null) {
                $this->api()->setProvider($this->previous);
            }

            // A later application may still be running. Link its restoration past this stopped installation.
            foreach (self::$active ?? [] as $lifecycle => $_) {
                if ($lifecycle->previousOwner === $this) {
                    $lifecycle->previous = $this->previous;
                    $lifecycle->previousOwner = $this->previousOwner;
                }
            }
        }

        if (self::$active !== null) {
            unset(self::$active[$this]);
        }
        $this->installed = null;
        $this->previous = null;
        $this->previousOwner = null;
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

    private function activeOwner(Provider $provider): ?self
    {
        $owner = null;
        foreach (self::$active ?? [] as $lifecycle => $_) {
            if ($lifecycle->api() === $this->api() && $lifecycle->installed === $provider && ($owner === null || $lifecycle->sequence > $owner->sequence)) {
                $owner = $lifecycle;
            }
        }

        return $owner;
    }
}
