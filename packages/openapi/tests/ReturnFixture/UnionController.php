<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Tests\ReturnFixture;

use Firefly\Web\Attributes\GetMapping;
use Firefly\Web\Attributes\PostMapping;
use Firefly\Web\Attributes\RequestBody;
use Firefly\Web\Attributes\RequestMapping;
use Firefly\Web\Attributes\RestController;
use stdClass;

/**
 * Actions whose DECLARED return type is a union, a nullable, or an open object — with no `@return` to lean on.
 */
#[RestController]
#[RequestMapping('/unions')]
final class UnionController
{
    private int $calls = 0;

    #[GetMapping('/optional')]
    public function optional(): ?Parcel
    {
        return $this->odd() ? new Parcel('P-1', 120) : null;
    }

    #[GetMapping('/either')]
    public function either(): Parcel|Label
    {
        return $this->odd() ? new Parcel('P-1', 120) : new Label('FRAGILE');
    }

    #[GetMapping('/scalar')]
    public function scalar(): int|string
    {
        return $this->odd() ? 1 : 'one';
    }

    /**
     * A `@return` that says no more than the declared type — so the declared `?array` is what documents it.
     *
     * @return array<string, mixed>|null
     */
    #[GetMapping('/maybe-map')]
    public function maybeMap(): ?array
    {
        return $this->odd() ? ['open' => 1] : null;
    }

    #[GetMapping('/bag')]
    public function bag(): stdClass
    {
        return new stdClass;
    }

    #[GetMapping('/object')]
    public function object(): object
    {
        return new stdClass;
    }

    #[GetMapping('/tagged')]
    public function tagged(): Tagged
    {
        return new Tagged(1, null, null);
    }

    #[PostMapping(status: 204)]
    public function tag(#[RequestBody] TagRequest $request): void {}

    /** Private, so RouteScanner — which reads public methods only — never mistakes it for an action. */
    private function odd(): bool
    {
        return ++$this->calls % 2 === 1;
    }
}
