<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Tests\Fixtures\Moods;

/** A pure enum: PayloadNormalizer stores its case name. */
enum Mood
{
    case Happy;
    case Grumpy;
}
