<?php

declare(strict_types=1);

use Firefly\Context\Boot\ApplicationContext;
use Firefly\OpenApi\Security\SecuritySchemeContributor;
use Firefly\Security\OAuth2\Server\OpenApi\AuthorizationServerSchemeContributor;
use Firefly\Security\OAuth2\Server\Tests\Support\OAuth2ServerBootTestCase;

uses(OAuth2ServerBootTestCase::class);

/**
 * THE ONE THING A UNIT TEST OF THIS CONTRIBUTOR CANNOT CATCH: whether the container TAG
 * OpenApiAutoConfiguration::securityModel() reads is written for it. `Container::getAll()` resolves
 * `firefly.contract.<interface>`, and ContainerRegistrar writes that tag only while walking the SCANNED
 * ComponentManifest — so a contributor registered as a #[Bean] returning its own concrete type would be
 * built and then silently dropped, with a document quietly missing its oauth2 scheme as the only symptom.
 *
 * The boot is the server's own: the master flag, session security and the authorization server on, with a
 * real signing key, exactly as every other test in this package runs it. What comes back out of the tag is
 * asked for its schemes, so the assertion covers the whole path — registered, tagged, constructible from
 * the container's own settings and client store, and answering with the flow URLs THIS boot's issuer
 * produces.
 */
it('reaches openapi through the container tag getAll() reads, and answers with this boot\'s own flow URLs', function () {
    /** @var OAuth2ServerBootTestCase $this */
    /** @var ApplicationContext $context */
    $context = $this->app()->make(ApplicationContext::class);

    $contributors = array_values(array_filter(
        $context->getAll(SecuritySchemeContributor::class),
        static fn (object $contributor): bool => $contributor instanceof AuthorizationServerSchemeContributor,
    ));

    expect($contributors)->toHaveCount(1);

    // The default `memory` client store is empty in this boot, so no client has registered a scope — and the
    // scheme is published all the same, with an empty `scopes` map, because the flow URLs are the server's
    // own and MethodSecurityRequirementContributor names this scheme from the same enabled flag. A
    // contributor that fell silent here would leave that name dangling in the document.
    $schemes = $contributors[0]->schemes();

    expect($schemes)->toHaveCount(1)
        ->and($schemes[0]->name)->toBe('oauth2AuthorizationCode')
        ->and($schemes[0]->definition['flows'])->toBe([
            'authorizationCode' => [
                'authorizationUrl' => 'http://localhost/oauth2/authorize',
                'tokenUrl' => 'http://localhost/oauth2/token',
                'scopes' => [],
            ],
        ]);
});
