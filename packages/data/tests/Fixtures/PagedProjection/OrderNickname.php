<?php

declare(strict_types=1);

namespace Firefly\Data\Tests\Fixtures\PagedProjection;

/** A projection onto a column the `orders` table does not have: the first-use refusal's fixture. */
final readonly class OrderNickname
{
    public function __construct(
        public int $id,
        public string $nickname,
    ) {}
}
