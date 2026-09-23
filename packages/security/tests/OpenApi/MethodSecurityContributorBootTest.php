<?php

declare(strict_types=1);

use Firefly\Context\Boot\ApplicationContext;
use Firefly\Cqrs\CqrsServiceProvider;
use Firefly\Cqrs\CqrsWiringProvider;
use Firefly\OpenApi\Security\SecurityRequirementContributor;
use Firefly\Security\OpenApi\MethodSecurityRequirementContributor;
use Firefly\Security\SecurityServiceProvider;
use Firefly\Security\SecurityWiringProvider;
use Illuminate\Foundation\Application;

/**
 * THE ONE THING A UNIT TEST OF THIS CONTRIBUTOR CANNOT CATCH.
 *
 * OpenApiAutoConfiguration::securityModel() collects contributors with `Container::getAll()`, which resolves
 * the container tag `firefly.contract.<interface>`, and ContainerRegistrar writes that tag only while
 * walking the SCANNED ComponentManifest. Registered the obvious way — a #[Bean] factory returning this
 * concrete type — the contributor would be built and then silently dropped: no error, no failing unit test,
 * just a document quietly missing its requirements. So the registration itself is asserted through a REAL
 * boot of the package's own providers, against the very tag the generator reads.
 *
 * It is also where the SECOND registration failure shows up: this class is a #[Component], so it is
 * resolved eagerly, and a constructor the container cannot satisfy is a boot crash rather than a missing
 * key in a JSON file.
 *
 * @param  array<string,mixed>  $security  the `firefly.security.*` tree for this boot
 */
function bootSecurityOpenApiAppWith(array $security): Application
{
    return fireflyApplication(
        config: ['firefly' => ['cqrs' => [], 'security' => $security]],
        providers: [CqrsServiceProvider::class, CqrsWiringProvider::class, SecurityServiceProvider::class, SecurityWiringProvider::class],
        needs: ['cache'],
    );
}

it('reaches openapi through the container tag getAll() reads, built from the real method manifest', function () {
    /** @var ApplicationContext $context */
    $context = bootSecurityOpenApiAppWith(['enabled' => true, 'jwt' => ['enabled' => true, 'secret' => str_repeat('s', 40)]])
        ->make(ApplicationContext::class);

    $contributors = $context->getAll(SecurityRequirementContributor::class);

    expect(array_map(static fn (object $contributor): string => $contributor::class, $contributors))
        ->toContain(MethodSecurityRequirementContributor::class);
});

it('contributes nothing at all when the security master flag is off', function () {
    /** @var ApplicationContext $context */
    $context = bootSecurityOpenApiAppWith(['enabled' => false])->make(ApplicationContext::class);

    expect(array_map(static fn (object $contributor): string => $contributor::class, $context->getAll(SecurityRequirementContributor::class)))
        ->not->toContain(MethodSecurityRequirementContributor::class);
});
