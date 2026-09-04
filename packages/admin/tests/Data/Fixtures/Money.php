<?php

declare(strict_types=1);

namespace Firefly\Admin\Tests\Data\Fixtures;

use Stringable;

/** An ordinary value object: printable, but only through __toString. */
final readonly class Money implements Stringable
{
    public function __construct(public string $amount, public string $currency) {}

    public function __toString(): string
    {
        return $this->amount.' '.$this->currency;
    }
}
