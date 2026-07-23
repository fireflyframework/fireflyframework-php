<?php

declare(strict_types=1);

namespace Firefly\Eda\Attributes;

use Attribute;

/**
 * Marks a public bean method as an eda broker-bus listener for one or more event-type PATTERNS (fnmatch globs like
 * "user.*"). This is a DIFFERENT surface from the shipped in-process #[AsEventListener] (which listens for PHP
 * event CLASSES dispatched synchronously): #[EventListener] subscribes to broker event-type strings and is
 * delivered by the eda adapters. INERT METADATA ONLY — discovery + subscription live in EventListenerScanner
 * (the sole reflection site) → EventListenerManifest → EventListenerWiringPass. IS_REPEATABLE: a method may carry
 * several. A single string is normalised to a one-element pattern list; order follows the #[Order] convention
 * (lower first), default 0.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class EventListener
{
    /** @var list<string> */
    public readonly array $patterns;

    /**
     * @param  string|array<int, string>  $patterns
     */
    public function __construct(string|array $patterns = [], public readonly int $order = 0)
    {
        $this->patterns = is_string($patterns) ? [$patterns] : array_values($patterns);
    }
}
