<?php

declare(strict_types=1);

namespace Firefly\Security\Tests\Fixtures\Flows;

use Firefly\Web\Attributes\GetMapping;
use Firefly\Web\Attributes\RestController;
use Illuminate\Http\Request;

/** A permitAll path, so a suite can prove a rule opens something without signing in. */
#[RestController]
final class OpenController
{
    /** @return array{open: bool} */
    #[GetMapping('/open')]
    public function open(): array
    {
        return ['open' => true];
    }

    #[GetMapping('/open/mark')]
    public function mark(Request $request): string
    {
        $request->session()->put('marker', 'set');

        return 'marked';
    }
}
