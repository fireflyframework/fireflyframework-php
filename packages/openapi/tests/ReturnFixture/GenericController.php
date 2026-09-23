<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Tests\ReturnFixture;

use Firefly\OpenApi\Attributes\ApiResponse;
use Firefly\Web\Attributes\GetMapping;
use Firefly\Web\Attributes\RequestMapping;
use Firefly\Web\Attributes\RestController;
use Illuminate\Support\LazyCollection;

/**
 * Actions returning generic classes, instantiated the way PHPStan requires them to be written.
 */
#[RestController]
#[RequestMapping('/generic')]
final class GenericController
{
    /** @return Batch<Parcel> */
    #[GetMapping('/parcels')]
    #[ApiResponse(status: 206, description: 'A partial batch.', type: 'Batch<Parcel>')]
    public function parcels(): Batch
    {
        return new Batch([new Parcel('P-1', 120)], 1);
    }

    /** @return Batch<Label> */
    #[GetMapping('/labels')]
    public function labels(): Batch
    {
        return new Batch([new Label('FRAGILE')], 1);
    }

    /** @return Batch<string> */
    #[GetMapping('/codes')]
    public function codes(): Batch
    {
        return new Batch(['P-1'], 1);
    }

    /** @return Batch<list<Parcel>> */
    #[GetMapping('/groups')]
    public function groups(): Batch
    {
        return new Batch([$this->group()], 1);
    }

    /** @return Batch<mixed> */
    #[GetMapping('/anything')]
    public function anything(): Batch
    {
        return new Batch([$this->anyValue()], 1);
    }

    #[GetMapping('/enveloped')]
    public function enveloped(): ParcelEnvelope
    {
        return new ParcelEnvelope(new Parcel('P-1', 120), '2026-09-23T00:00:00Z');
    }

    /** @return Tree<Parcel> */
    #[GetMapping('/tree')]
    public function tree(): Tree
    {
        return new Tree(new Parcel('P-1', 120), []);
    }

    /** @return Tree<list<Parcel>> */
    #[GetMapping('/forest')]
    public function forest(): Tree
    {
        return new Tree($this->group(), []);
    }

    /** @return LazyCollection<int, Parcel> */
    #[GetMapping('/collected')]
    public function collected(): LazyCollection
    {
        return LazyCollection::make([new Parcel('P-1', 120)]);
    }

    /**
     * Private helpers, which RouteScanner never reads: they only give PHPStan a declared type to infer each
     * instantiation's argument from, so every `@return` above is checked rather than trusted.
     *
     * @return list<Parcel>
     */
    private function group(): array
    {
        return [new Parcel('P-1', 120)];
    }

    private function anyValue(): mixed
    {
        return 'P-1';
    }
}
