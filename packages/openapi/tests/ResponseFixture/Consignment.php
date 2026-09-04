<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Tests\ResponseFixture;

use JsonSerializable;

/**
 * A consignment as the API publishes it.
 *
 * The wire shape is NOT the property list: `weightGrams` is derived and published, and the private
 * `$auditTrail` is not published at all. That divergence is the whole reason a response schema is built from
 * the declared shape rather than from reflection.
 */
final readonly class Consignment implements JsonSerializable
{
    /**
     * @param  non-empty-string  $reference
     * @param  list<Shipment>  $shipments
     * @param  list<positive-int>  $parcelGrams
     */
    public function __construct(
        public string $reference,
        public Money $declaredValue,
        public array $shipments,
        private array $parcelGrams = [],
        private string $auditTrail = '',
    ) {}

    /** @return positive-int */
    public function weightGrams(): int
    {
        return max(1, array_sum($this->parcelGrams));
    }

    /**
     * Public, and deliberately NOT part of the wire shape — a class may expose more to PHP than it publishes
     * over HTTP, which is the second half of why a response schema is built from jsonSerialize() rather than
     * from what happens to be reachable.
     */
    public function auditTrail(): string
    {
        return $this->auditTrail;
    }

    /** @return array{reference: non-empty-string, declaredValue: Money, shipments: list<Shipment>, weightGrams: positive-int} */
    public function jsonSerialize(): array
    {
        return [
            'reference' => $this->reference,
            'declaredValue' => $this->declaredValue,
            'shipments' => $this->shipments,
            'weightGrams' => $this->weightGrams(),
        ];
    }
}
