<?php

declare(strict_types=1);

namespace Firefly\Web\Tests\Fixtures;

use Firefly\Web\Attributes\GetMapping;
use Firefly\Web\Attributes\PathVariable;
use Firefly\Web\Attributes\RequestMapping;
use Firefly\Web\Attributes\RestController;

/** A controller whose id segment declares its SHAPE and the 404 a malformed one is answered with. */
#[RestController]
#[RequestMapping('/rooms')]
final class RoomsController
{
    /** @return array<string,mixed> */
    #[GetMapping('/{roomId}')]
    public function show(#[PathVariable(pattern: PathVariable::UUID, notFoundCode: 'ROOM_NOT_FOUND', notFoundMessage: 'That room does not exist, or is not yours.')] string $roomId): array
    {
        return ['id' => $roomId];
    }

    /** @return array<string,mixed> */
    #[GetMapping('/{roomId}/members/{memberId}')]
    public function member(
        #[PathVariable(pattern: PathVariable::UUID, notFoundCode: 'ROOM_NOT_FOUND')] string $roomId,
        #[PathVariable(pattern: '[0-9]+')] string $memberId,
    ): array {
        return ['room' => $roomId, 'member' => $memberId];
    }
}
