<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Tests\Support;

use Firefly\Context\Scan\AppScan;
use Firefly\OpenApi\Generator\OpenApiGenerator;
use Firefly\OpenApi\Generator\OperationFactory;
use Firefly\OpenApi\OpenApiProperties;
use Firefly\OpenApi\Schema\ConstraintSchemaMapper;
use Firefly\OpenApi\Schema\DtoSchemaFactory;
use Firefly\Validation\Constraint\ConstraintManifest;
use Firefly\Validation\Constraint\ConstraintManifestCompiler;
use Firefly\Web\Route\RouteManifest;
use Firefly\Web\Route\RouteScanner;

/**
 * Builds the generator over the REAL scanners, from the REAL fixture controller — never from a hand-written
 * RouteDescriptor or a hand-written rule array.
 *
 * That is the whole design of these tests. A hand-built descriptor asserts that the generator maps the shape
 * its author BELIEVED the scanner produces; running RouteScanner and ConstraintManifestCompiler asserts that
 * it maps the shape the framework ACTUALLY produces. The two diverge the moment packages/web changes a
 * binding `kind` or packages/validation changes a `toRules()` body — which is precisely the drift a generated
 * document must never survive silently, and precisely what these tests are here to catch.
 */
final class FixtureDocument
{
    /**
     * @return array<string, string>
     */
    public static function psr4(): array
    {
        return ['Firefly\\OpenApi\\Tests\\Fixture\\' => dirname(__DIR__).'/Fixture'];
    }

    public static function routes(): RouteManifest
    {
        return new RouteManifest((new RouteScanner)->scan(self::psr4()));
    }

    public static function constraints(): ConstraintManifest
    {
        return ConstraintManifest::fromArray((new ConstraintManifestCompiler)->toArray(AppScan::classes(self::psr4())));
    }

    public static function schemas(): DtoSchemaFactory
    {
        return new DtoSchemaFactory(self::constraints(), new ConstraintSchemaMapper);
    }

    public static function generator(?OpenApiProperties $properties = null): OpenApiGenerator
    {
        return new OpenApiGenerator(
            self::routes(),
            $properties ?? self::properties(),
            new OperationFactory(self::schemas()),
        );
    }

    /**
     * @param  list<array{url: string, description?: string}>  $servers
     * @param  list<string>  $exclude
     */
    public static function properties(array $servers = [], array $exclude = [], bool $includeHtml = false): OpenApiProperties
    {
        return new OpenApiProperties(
            enabled: true,
            specPath: 'openapi.json',
            viewerEnabled: true,
            viewerPath: 'openapi',
            viewerCdn: false,
            title: 'Orders API',
            version: '1.2.3',
            description: 'The fixture API.',
            servers: $servers,
            excludePathPrefixes: $exclude,
            includeHtml: $includeHtml,
        );
    }

    /**
     * Walks the whole document collecting every local `$ref` pointer, so a test can prove each one RESOLVES
     * — the single most valuable structural check available, because a dangling pointer is exactly the flaw
     * that makes a client generator abort and is exactly the flaw a snapshot test cannot see.
     *
     * @return list<string>
     */
    public static function refs(mixed $document): array
    {
        if (! is_array($document)) {
            return [];
        }

        $refs = [];
        foreach ($document as $key => $value) {
            if ($key === '$ref' && is_string($value)) {
                $refs[] = $value;

                continue;
            }

            foreach (self::refs($value) as $nested) {
                $refs[] = $nested;
            }
        }

        return $refs;
    }

    /**
     * Resolves one local JSON Pointer against the document, or null when it dangles.
     *
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>|null
     */
    public static function resolve(array $document, string $ref): ?array
    {
        if (! str_starts_with($ref, '#/')) {
            return null;
        }

        $node = $document;
        foreach (explode('/', substr($ref, 2)) as $segment) {
            $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);
            if (! array_key_exists($segment, $node)) {
                return null;
            }

            $next = $node[$segment];
            if (! is_array($next)) {
                return null;
            }

            /** @var array<string, mixed> $next */
            $node = $next;
        }

        return $node;
    }
}
