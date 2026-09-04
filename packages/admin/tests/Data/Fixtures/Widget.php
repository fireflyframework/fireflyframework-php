<?php

declare(strict_types=1);

namespace Firefly\Admin\Tests\Data\Fixtures;

use DateTimeImmutable;
use stdClass;

/**
 * A plain entity holding one field of every kind the projector has to reduce to something a template can
 * print: a datetime, a backed enum, an array, a value object that is only printable through __toString, and
 * an object that is not printable at all.
 *
 * Its key is `uuid`, not `id` — the second entry in the identifier preference order, and the reason that
 * order exists.
 */
final class Widget
{
    /** @param list<string> $tags */
    public function __construct(
        public string $uuid,
        public string $name,
        public DateTimeImmutable $occurredAt,
        public WidgetStatus $status,
        public array $tags,
        public Money $price,
        public stdClass $opaque,
    ) {}
}
