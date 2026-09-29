<?php

declare(strict_types=1);

namespace Firefly\Admin\Route;

use Firefly\Web\Dispatch\ArgumentResolver;
use Firefly\Web\Route\RouteDescriptor;

/** @phpstan-import-type Binding from RouteDescriptor */
final readonly class RouteBinding
{
    public ?string $notFoundMessage;

    /** @param Binding $plan */
    public function __construct(public int $position, public array $plan, public ?string $resolver)
    {
        $this->notFoundMessage = isset($plan['pattern'])
            ? ($plan['notFoundMessage'] ?? ArgumentResolver::notFoundSentence($plan['name'])) : null;
    }

    public function supplied(): bool
    {
        // Resolver claims take precedence over every compiled kind, including query and service.
        return $this->resolver !== null || $this->plan['kind'] === 'service';
    }

    public function defaultLabel(): string
    {
        $encoded = json_encode($this->plan['default'], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        return $encoded !== false ? $encoded : 'null';
    }
}
