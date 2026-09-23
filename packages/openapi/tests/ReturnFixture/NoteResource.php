<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Tests\ReturnFixture;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A note, sent without the `data` envelope.
 */
final class NoteResource extends JsonResource
{
    /** @var string|null */
    public static $wrap = null;

    /** @return array{text: string} */
    public function toArray(Request $request): array
    {
        return ['text' => 'fragile'];
    }
}
