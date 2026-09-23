<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Tests\ReturnFixture;

use Firefly\Web\Attributes\GetMapping;
use Firefly\Web\Attributes\RequestMapping;
use Firefly\Web\Attributes\RestController;
use Illuminate\Contracts\Pagination\LengthAwarePaginator as LengthAwarePaginatorContract;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;

/**
 * Actions returning Laravel's three paginators — `->paginate()`, `->simplePaginate()`, `->cursorPaginate()`.
 */
#[RestController]
#[RequestMapping('/paged')]
final class PagedController
{
    /** @return LengthAwarePaginator<int, Parcel> */
    #[GetMapping('/parcels')]
    public function parcels(): LengthAwarePaginator
    {
        return new LengthAwarePaginator([new Parcel('P-1', 120)], 1, 20);
    }

    /** @return LengthAwarePaginatorContract<int, Parcel> */
    #[GetMapping('/contract')]
    public function contract(): LengthAwarePaginatorContract
    {
        return new LengthAwarePaginator([new Parcel('P-1', 120)], 1, 20);
    }

    /** @return Paginator<int, Label> */
    #[GetMapping('/labels')]
    public function labels(): Paginator
    {
        return new Paginator([new Label('FRAGILE')], 20);
    }

    /** @return CursorPaginator<int, Parcel> */
    #[GetMapping('/cursor')]
    public function cursor(): CursorPaginator
    {
        return new CursorPaginator([new Parcel('P-1', 120)], 20);
    }

    /** @return LengthAwarePaginator<int, mixed> */
    #[GetMapping('/raw')]
    public function raw(): LengthAwarePaginator
    {
        return new LengthAwarePaginator([], 0, 20);
    }
}
