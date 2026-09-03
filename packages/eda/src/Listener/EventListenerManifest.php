<?php

declare(strict_types=1);

namespace Firefly\Eda\Listener;

use Firefly\Kernel\Exception\Framework\ConfigurationException;

/**
 * The compiled, reflection-free eda-listener source the EventListenerWiringPass reads at boot. Loaded via
 * require+map; the descriptors are pure arrays, so the whole manifest is a plain PHP array literal. Mirrors the
 * ScheduledManifest / RouteManifest idiom.
 *
 * @phpstan-import-type EventListenerRow from EventListenerDescriptor
 */
final class EventListenerManifest
{
    /**
     * @param  list<EventListenerDescriptor>  $listeners
     */
    public function __construct(private readonly array $listeners) {}

    /**
     * @param  array<int, EventListenerRow>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(array_map(
            static fn (array $row): EventListenerDescriptor => EventListenerDescriptor::fromArray($row),
            array_values($data),
        ));
    }

    public static function load(string $path): self
    {
        if (! is_file($path)) {
            throw new ConfigurationException("Event-listener manifest not found at {$path}. Run the event-listener scan first.");
        }

        /** @var mixed $data */
        $data = require $path;
        if (! is_array($data)) {
            throw new ConfigurationException("Event-listener manifest at {$path} did not return an array.");
        }

        /** @var array<int, EventListenerRow> $data */
        return self::fromArray($data);
    }

    /**
     * The compiled listeners in the order the manifest was written (scanner order in practice: FQCN-sorted
     * classes, then each class's methods in declaration order, then repeated attributes in written order).
     * Callers that DISPATCH must use ordered() instead — see its docblock for why.
     *
     * @return list<EventListenerDescriptor>
     */
    public function all(): array
    {
        return $this->listeners;
    }

    /**
     * The dispatch order: listeners sorted by their declared #[EventListener(order:)] ASCENDING (lower first,
     * the #[Order] convention used throughout the framework), ties keeping compiled-manifest order.
     *
     * WHY THIS EXISTS. `order` was scanned by EventListenerScanner, carried through EventListenerDescriptor,
     * var_export'd into the compiled manifest, read back by fromArray() — and then NEVER CONSULTED. The one
     * place that could have used it, EventListenerWiringPass, looped over all() and subscribed in that order,
     * and SubscriberRegistry::deliver() fires handlers in subscription order. So the declared order was
     * silently discarded and the real dispatch sequence was "whatever the scanner emitted" — effectively FQCN
     * order. A listener that declared order: -100 to run an audit hook first ran wherever its class name
     * happened to sort. Nothing failed; the ordering was simply a lie. Caught by publishing one event to three
     * listeners whose declared orders (30/10/20) disagree with their alphabetical order on every position.
     *
     * TIE-BREAK. Equal orders keep compiled-manifest order. usort() has been guaranteed STABLE since PHP 8.0,
     * so this is a real guarantee and not an accident of the sort implementation — which matters, because a
     * non-deterministic tie-break would let an app pass CI and reorder itself in production. Compiled-manifest
     * order is itself deterministic (EventListenerScanner sort()s the discovered FQCNs, and reflection returns
     * a class's methods in declaration order), so the whole sequence is reproducible from the source tree.
     *
     * @return list<EventListenerDescriptor>
     */
    public function ordered(): array
    {
        $ordered = $this->listeners;
        usort(
            $ordered,
            static fn (EventListenerDescriptor $a, EventListenerDescriptor $b): int => $a->order <=> $b->order,
        );

        return $ordered;
    }
}
