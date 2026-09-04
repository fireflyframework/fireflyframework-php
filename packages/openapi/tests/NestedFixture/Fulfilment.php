<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Tests\NestedFixture;

/**
 * The element type of a list of ENUMS rather than of DTOs. `list<Fulfilment>` must produce
 * `items: {type: string, enum: [...]}` inline — an enum is not a component, so turning it into a `$ref`
 * would mint a named type per enum in every generated client for no gain.
 */
enum Fulfilment: string
{
    case Standard = 'standard';
    case Express = 'express';
}
