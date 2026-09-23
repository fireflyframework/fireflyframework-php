<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Tests\ReturnFixture;

use Firefly\OpenApi\Attributes\ApiResponse;
use Firefly\Web\Attributes\GetMapping;
use Firefly\Web\Attributes\PostMapping;
use Firefly\Web\Attributes\RequestMapping;
use Firefly\Web\Attributes\RestController;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Actions returning Laravel API resources.
 */
#[RestController]
#[RequestMapping('/resources')]
final class ResourceController
{
    #[GetMapping('/parcel')]
    #[ApiResponse(status: 202, description: 'Queued for a rescan.', type: ParcelResource::class)]
    public function parcel(): ParcelResource
    {
        return new ParcelResource(new Parcel('P-1', 120));
    }

    #[GetMapping('/crate')]
    public function crate(): CrateResource
    {
        return new CrateResource(new CrateEntity);
    }

    #[GetMapping('/note')]
    public function note(): NoteResource
    {
        return new NoteResource(null);
    }

    #[GetMapping('/parcels')]
    public function parcels(): ParcelResourceCollection
    {
        return new ParcelResourceCollection([]);
    }

    #[GetMapping('/manifest')]
    public function manifest(): ManifestCollection
    {
        return new ManifestCollection([]);
    }

    #[GetMapping('/stack')]
    public function stack(): StackCollection
    {
        return new StackCollection([]);
    }

    #[GetMapping('/anonymous')]
    public function anonymous(): AnonymousResourceCollection
    {
        return ParcelResource::collection([]);
    }

    #[PostMapping(status: 201)]
    public function create(): ParcelResource
    {
        return new ParcelResource(new Parcel('P-1', 120));
    }
}
