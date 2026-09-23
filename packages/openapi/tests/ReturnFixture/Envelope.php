<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Tests\ReturnFixture;

/**
 * A payload and when it was issued.
 *
 * @template TData
 */
abstract readonly class Envelope
{
    /**
     * @param  TData  $data
     */
    public function __construct(
        public mixed $data,
        public string $issuedAt,
    ) {}
}
