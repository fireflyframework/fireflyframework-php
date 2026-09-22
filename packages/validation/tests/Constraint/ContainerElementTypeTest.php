<?php

declare(strict_types=1);

use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Validation\Constraint\ContainerElementType;
use Firefly\Validation\Tests\Fixtures\Constraint\Lists\AliasedPayload;
use Firefly\Validation\Tests\Fixtures\Constraint\Lists\CartPayload;
use Firefly\Validation\Tests\Fixtures\Constraint\Lists\EachPayload;
use Firefly\Validation\Tests\Fixtures\Constraint\Lists\LinePayload;
use Firefly\Validation\Tests\Fixtures\Constraint\Lists\MissingEachPayload;
use Firefly\Validation\Tests\Fixtures\Constraint\Lists\NestedListPayload;
use Firefly\Validation\Tests\Fixtures\Constraint\Lists\ScalarListPayload;
use Firefly\Validation\Tests\Fixtures\Constraint\Lists\UntypedListPayload;
use Firefly\Validation\Tests\Fixtures\Constraint\Lists\VarTaggedPayload;

/**
 * @param  class-string  $class
 */
function elementOf(string $class, string $member): ?string
{
    foreach ((new ReflectionClass($class))->getConstructor()?->getParameters() ?? [] as $parameter) {
        if ($parameter->getName() === $member) {
            return ContainerElementType::of($parameter);
        }
    }

    throw new RuntimeException("{$class} has no constructor parameter \${$member}.");
}

it('reads the element class from #[Valid(each:)] first', function () {
    expect(elementOf(EachPayload::class, 'lines'))->toBe(LinePayload::class);
});

it('reads a @var tag on the promoted parameter itself', function () {
    expect(elementOf(VarTaggedPayload::class, 'lines'))->toBe(LinePayload::class);
});

it('reads the constructor @param, resolving a same-namespace name and a use alias', function () {
    expect(elementOf(CartPayload::class, 'lines'))->toBe(LinePayload::class)
        ->and(elementOf(AliasedPayload::class, 'lines'))->toBe(LinePayload::class);
});

it('answers null for a scalar list, a nested list and an undocumented array', function () {
    expect(elementOf(ScalarListPayload::class, 'tags'))->toBeNull()
        ->and(elementOf(NestedListPayload::class, 'grid'))->toBeNull()
        ->and(elementOf(UntypedListPayload::class, 'items'))->toBeNull();
});

it('refuses an each: that names no class, by name', function () {
    expect(fn () => elementOf(MissingEachPayload::class, 'items'))
        ->toThrow(ConfigurationException::class, 'App\\Missing\\LinePayload')
        ->toThrow(ConfigurationException::class, MissingEachPayload::class.'::$items');
});

it('understands every list spelling a docblock uses, with or without null', function () {
    $declaring = new ReflectionClass(CartPayload::class);

    expect(ContainerElementType::fromParams('/** @param list<LinePayload> $a */', $declaring))->toBe(['a' => LinePayload::class])
        ->and(ContainerElementType::fromParams('/** @param array<int, LinePayload> $a */', $declaring))->toBe(['a' => LinePayload::class])
        ->and(ContainerElementType::fromParams('/** @param iterable<LinePayload> $a */', $declaring))->toBe(['a' => LinePayload::class])
        ->and(ContainerElementType::fromParams('/** @param non-empty-list<LinePayload> $a */', $declaring))->toBe(['a' => LinePayload::class])
        ->and(ContainerElementType::fromParams('/** @param LinePayload[] $a */', $declaring))->toBe(['a' => LinePayload::class])
        ->and(ContainerElementType::fromParams('/** @param ?list<LinePayload> $a */', $declaring))->toBe(['a' => LinePayload::class])
        ->and(ContainerElementType::fromParams('/** @param list<LinePayload>|null $a */', $declaring))->toBe(['a' => LinePayload::class])
        ->and(ContainerElementType::fromParams('/** @param \\Firefly\\Validation\\Tests\\Fixtures\\Constraint\\Lists\\LinePayload[] $a */', $declaring))->toBe(['a' => LinePayload::class])
        ->and(ContainerElementType::fromParams('/** @param list<Nope> $a */', $declaring))->toBe([])
        ->and(ContainerElementType::fromVar('/** @var list<LinePayload> */', $declaring))->toBe(LinePayload::class)
        ->and(ContainerElementType::fromVar('/** @var LinePayload[]|null */', $declaring))->toBe(LinePayload::class)
        ->and(ContainerElementType::fromVar('/** @var list<list<LinePayload>> */', $declaring))->toBeNull()
        ->and(ContainerElementType::fromVar('', $declaring))->toBeNull();
});
