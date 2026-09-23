<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Tests\Support;

use Firefly\OpenApi\OpenApiServiceProvider;
use Firefly\OpenApi\OpenApiWiringProvider;

/**
 * THE INVARIANT NO SINGLE PACKAGE CAN ASSERT: firefly/openapi booted beside this authorization server, with
 * a method-secured route on it, so the two halves of "the document names the credential a caller will
 * actually be challenged for" meet in one process — `oauth2AuthorizationCode` is named by
 * firefly/security's MethodSecurityRequirementContributor and published by this package's
 * AuthorizationServerSchemeContributor, and NOTHING else in the tree can see both at once. The openapi
 * capstone cannot: `deptrac.yaml` gives that package no edge to either security package, it boots neither
 * this one nor a method-secured fixture, and its "no orphan in either direction" assertion therefore never
 * sees this fourth scheme name.
 *
 * THE CLIENT REGISTRY IS DELIBERATELY EMPTY, which is the configuration that used to produce the failure:
 * a scheme contributor that published nothing without an authorization-code client, beside a requirement
 * contributor naming the scheme from `firefly.security.oauth2.server.enabled` alone, left every
 * method-secured operation pointing at a `components.securitySchemes` entry that did not exist. It is not
 * an exotic setup — a client_credentials-only token issuer has no such client, and neither does an
 * `eloquent` client table that is empty or unreachable when CI generates the document.
 *
 * The fixture controller is firefly/security's: the rules it carries are that package's attributes, the
 * manifest is compiled by the in-process scan exactly as an uncached application compiles it, and copying
 * it here would only let the two copies drift.
 */
abstract class AuthorizationServerDocumentCapstoneTestCase extends OAuth2ServerCapstoneTestCase
{
    protected function fireflyProviders(): array
    {
        return [
            ...parent::fireflyProviders(),
            OpenApiServiceProvider::class,
            OpenApiWiringProvider::class,
        ];
    }

    protected function fixturePaths(): array
    {
        return [
            ...parent::fixturePaths(),
            'Firefly\\Security\\Tests\\Fixtures\\OpenApiDoc\\' => dirname(__DIR__, 3).'/security/tests/Fixtures/OpenApiDoc',
        ];
    }

    /** No client at all: the empty registry a document generated in CI is so often produced against. */
    protected function clients(): array
    {
        return [];
    }

    protected function serverOverrides(): array
    {
        return [
            'firefly.openapi.enabled' => true,
            'firefly.openapi.viewer.enabled' => false,
            'firefly.openapi.title' => 'Issuer API',
            'firefly.openapi.version' => '1.0.0',
            // The spec route is an ordinary route and these rules are deny-by-default, so without a rule of
            // its own this capstone would be asserting against a 401.
            'firefly.security.http.rules' => [
                ['pattern' => '/openapi.json', 'access' => 'permitAll'],
                ['pattern' => 'open', 'access' => 'permitAll'],
                ['pattern' => 'open/*', 'access' => 'permitAll'],
                ['pattern' => '*', 'access' => 'authenticated'],
            ],
        ];
    }

    /**
     * The served document, decoded.
     *
     * @return array<string, mixed>
     */
    public function document(): array
    {
        /** @var array<string, mixed> $document */
        $document = json_decode($this->responseBody($this->get('/openapi.json')->assertStatus(200)), true, flags: JSON_THROW_ON_ERROR);

        return $document;
    }

    /** @return list<string> every scheme name the document's operations refer to, sorted */
    public function namedSchemes(): array
    {
        $named = [];

        /** @var array<string, array<string, mixed>> $paths */
        $paths = $this->document()['paths'];
        foreach ($paths as $operations) {
            foreach ($operations as $operation) {
                if (! is_array($operation) || ! isset($operation['security']) || ! is_array($operation['security'])) {
                    continue;
                }
                /** @var list<array<string, mixed>> $entries */
                $entries = $operation['security'];
                foreach ($entries as $entry) {
                    foreach (array_keys($entry) as $scheme) {
                        $named[(string) $scheme] = true;
                    }
                }
            }
        }

        $names = array_keys($named);
        sort($names);

        return $names;
    }

    /** @return list<string> every scheme name `components.securitySchemes` publishes, sorted */
    public function publishedSchemes(): array
    {
        /** @var array<string, array<string, mixed>> $components */
        $components = $this->document()['components'];

        $names = array_keys($components['securitySchemes']);
        sort($names);

        return $names;
    }
}
