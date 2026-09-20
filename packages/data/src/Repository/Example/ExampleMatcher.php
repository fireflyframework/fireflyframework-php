<?php

declare(strict_types=1);

namespace Firefly\Data\Repository\Example;

/**
 * The rules an Example is compared under — Spring's ExampleMatcher, ported as an immutable value object whose
 * every `with*` returns a new instance. matchingAll() folds the probe's properties with AND (the default),
 * matchingAny() with OR. Nulls in the probe are skipped unless withIncludeNullValues() (then `IS NULL`);
 * withIgnorePaths() drops properties outright (the usual reason: an id or a timestamp copied along with the
 * probe); withStringMatcher() sets the comparison for every string property, withIgnoreCase() with no paths
 * makes every string comparison case-insensitive, with paths only those; withMatcher() overrides both for one
 * path. Only STRING probe values get string matching — an int, float or bool is always `=`.
 */
final readonly class ExampleMatcher
{
    /**
     * @param  list<string>  $ignoredPaths
     * @param  list<string>  $ignoreCasePaths
     * @param  array<string, GenericPropertyMatcher>  $propertyMatchers
     */
    private function __construct(
        private bool $allMatching = true,
        private array $ignoredPaths = [],
        private bool $ignoreCaseAll = false,
        private array $ignoreCasePaths = [],
        private bool $includeNullValues = false,
        private StringMatcher $defaultStringMatcher = StringMatcher::EXACT,
        private array $propertyMatchers = [],
    ) {}

    public static function matching(): self
    {
        return new self;
    }

    public static function matchingAll(): self
    {
        return new self;
    }

    public static function matchingAny(): self
    {
        return new self(allMatching: false);
    }

    public function withIgnorePaths(string ...$paths): self
    {
        return $this->copy(ignoredPaths: array_values(array_unique([...$this->ignoredPaths, ...$paths])));
    }

    /** No paths: every string property ignores case. With paths: only those. */
    public function withIgnoreCase(string ...$paths): self
    {
        return $paths === []
            ? $this->copy(ignoreCaseAll: true)
            : $this->copy(ignoreCasePaths: array_values(array_unique([...$this->ignoreCasePaths, ...$paths])));
    }

    public function withIncludeNullValues(): self
    {
        return $this->copy(includeNullValues: true);
    }

    public function withStringMatcher(StringMatcher $matcher): self
    {
        return $this->copy(defaultStringMatcher: $matcher);
    }

    public function withMatcher(string $path, GenericPropertyMatcher $matcher): self
    {
        return $this->copy(propertyMatchers: [...$this->propertyMatchers, $path => $matcher]);
    }

    public function isAllMatching(): bool
    {
        return $this->allMatching;
    }

    public function isIgnored(string $path): bool
    {
        return in_array($path, $this->ignoredPaths, true);
    }

    public function isIgnoreCase(string $path): bool
    {
        $override = ($this->propertyMatchers[$path] ?? null)?->ignoreCase;
        if ($override !== null) {
            return $override;
        }

        return $this->ignoreCaseAll || in_array($path, $this->ignoreCasePaths, true);
    }

    public function includesNullValues(): bool
    {
        return $this->includeNullValues;
    }

    public function stringMatcherFor(string $path): StringMatcher
    {
        $override = $this->propertyMatchers[$path] ?? null;

        return $override === null ? $this->defaultStringMatcher : $override->stringMatcher;
    }

    /**
     * @param  list<string>|null  $ignoredPaths
     * @param  list<string>|null  $ignoreCasePaths
     * @param  array<string, GenericPropertyMatcher>|null  $propertyMatchers
     */
    private function copy(
        ?bool $allMatching = null,
        ?array $ignoredPaths = null,
        ?bool $ignoreCaseAll = null,
        ?array $ignoreCasePaths = null,
        ?bool $includeNullValues = null,
        ?StringMatcher $defaultStringMatcher = null,
        ?array $propertyMatchers = null,
    ): self {
        return new self(
            $allMatching ?? $this->allMatching,
            $ignoredPaths ?? $this->ignoredPaths,
            $ignoreCaseAll ?? $this->ignoreCaseAll,
            $ignoreCasePaths ?? $this->ignoreCasePaths,
            $includeNullValues ?? $this->includeNullValues,
            $defaultStringMatcher ?? $this->defaultStringMatcher,
            $propertyMatchers ?? $this->propertyMatchers,
        );
    }
}
