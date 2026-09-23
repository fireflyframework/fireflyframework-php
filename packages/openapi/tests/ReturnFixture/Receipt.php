<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Tests\ReturnFixture;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * A value that builds its own response — whatever that turns out to be.
 */
final readonly class Receipt implements Responsable
{
    public function __construct(
        public string $number,
    ) {}

    /** @param  Request  $request */
    public function toResponse($request): Response
    {
        return new Response('receipt '.$this->number, 200, ['Content-Type' => 'text/plain']);
    }
}
