<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Tests\BindingFixture;

use Firefly\OpenApi\Attributes\ApiResponse;
use Firefly\Validation\Valid;
use Firefly\Web\Attributes\GetMapping;
use Firefly\Web\Attributes\PathVariable;
use Firefly\Web\Attributes\PostMapping;
use Firefly\Web\Attributes\RequestBody;
use Firefly\Web\Attributes\RestController;

#[RestController]
final class BindingController
{
    #[GetMapping('/binding/query')]
    public function query(#[Valid] string $value): string
    {
        return $value;
    }

    #[GetMapping('/binding/service')]
    public function service(#[Valid] Collaborator $service): string
    {
        return $service->value();
    }

    /** @param mixed $payload */
    #[PostMapping('/binding/untyped')]
    public function untyped(#[Valid] #[RequestBody] $payload): void {}

    #[GetMapping('/binding/pattern/{id}')]
    public function pattern(#[PathVariable(pattern: PathVariable::UUID, notFoundCode: 'ITEM_NOT_FOUND')] string $id): string
    {
        return $id;
    }

    #[GetMapping('/binding/plain/{id}')]
    public function plain(#[PathVariable] string $id): string
    {
        return $id;
    }

    #[ApiResponse(404, 'This identifier does not name an item.')]
    #[GetMapping('/binding/declared/{id}')]
    public function declared(#[PathVariable(pattern: PathVariable::UUID)] string $id): string
    {
        return $id;
    }
}
