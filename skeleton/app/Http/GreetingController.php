<?php

declare(strict_types=1);

namespace App\Http;

use App\GreetingService;
use Firefly\Web\Attributes\GetMapping;
use Firefly\Web\Attributes\PathVariable;
use Firefly\Web\Attributes\RestController;

/**
 * The sample Firefly slice: a #[RestController] whose routes are discovered by the RouteScanner and served
 * from the compiled RouteManifest. GreetingService is autowired via constructor DI.
 *
 * `/` belongs to App\Http\WelcomeController, a #[Controller] that renders HTML — this one returns a value
 * the ResponseFactory negotiates into JSON, which is the difference between the two stereotypes.
 */
#[RestController]
final class GreetingController
{
    public function __construct(private readonly GreetingService $greetings) {}

    /** @return array<string, string> */
    #[GetMapping('/greetings/{name}', name: 'greetings.show')]
    public function show(#[PathVariable] string $name): array
    {
        return ['message' => $this->greetings->greet($name)];
    }
}
