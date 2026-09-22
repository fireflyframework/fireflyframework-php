<?php

declare(strict_types=1);

namespace Firefly\Validation\Constraint;

/**
 * The one-line half of HasMessage every first-party constraint shares: the `message` element IS the
 * attribute's `$message` constructor property (Bean Validation names the element `message`, and so does
 * every attribute here), so "did the developer write one" is "is it non-null". A class using this trait
 * declares `public readonly ?string $message`; static analysis reads the trait in that class's context and
 * flags one that does not.
 */
trait MessageElement
{
    public function hasCustomMessage(): bool
    {
        return $this->message !== null;
    }
}
