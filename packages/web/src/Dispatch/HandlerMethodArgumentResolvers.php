<?php

declare(strict_types=1);

namespace Firefly\Web\Dispatch;

/**
 * The ordered registry of HandlerMethodArgumentResolvers — first added, first asked. A bound()-guarded
 * singleton in WebServiceProvider, so a capability's wiring pass can add() into it at boot and an
 * application can bind its own pre-populated instance.
 */
final class HandlerMethodArgumentResolvers
{
    /** @var list<HandlerMethodArgumentResolver> */
    private array $resolvers = [];

    public function add(HandlerMethodArgumentResolver $resolver): void
    {
        $this->resolvers[] = $resolver;
    }

    /**
     * @param  array<string, mixed>  $binding
     */
    public function resolverFor(array $binding): ?HandlerMethodArgumentResolver
    {
        foreach ($this->resolvers as $resolver) {
            if ($resolver->supports($binding)) {
                return $resolver;
            }
        }

        return null;
    }

    /**
     * @return list<HandlerMethodArgumentResolver>
     */
    public function all(): array
    {
        return $this->resolvers;
    }
}
