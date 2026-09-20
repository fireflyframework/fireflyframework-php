<?php

declare(strict_types=1);

use Firefly\Data\Repository\Example\Example;
use Firefly\Data\Repository\Example\ExampleMatcher;
use Firefly\Data\Repository\Example\GenericPropertyMatcher;
use Firefly\Data\Repository\Example\StringMatcher;
use Firefly\Data\Tests\Fixtures\Repository\Record;

it('defaults to matching all, case-sensitive exact, nulls ignored', function () {
    $matcher = ExampleMatcher::matching();

    expect($matcher->isAllMatching())->toBeTrue()
        ->and($matcher->isIgnoreCase('email'))->toBeFalse()
        ->and($matcher->includesNullValues())->toBeFalse()
        ->and($matcher->stringMatcherFor('email'))->toBe(StringMatcher::EXACT)
        ->and(ExampleMatcher::matchingAny()->isAllMatching())->toBeFalse();
});

it('is immutable: every wither returns a new matcher', function () {
    $base = ExampleMatcher::matchingAll();
    $tuned = $base->withIgnoreCase('email')->withStringMatcher(StringMatcher::CONTAINING)->withIgnorePaths('id')->withIncludeNullValues();

    expect($base->isIgnoreCase('email'))->toBeFalse()
        ->and($base->stringMatcherFor('email'))->toBe(StringMatcher::EXACT)
        ->and($tuned->isIgnoreCase('email'))->toBeTrue()
        ->and($tuned->isIgnoreCase('status'))->toBeFalse()
        ->and($tuned->stringMatcherFor('status'))->toBe(StringMatcher::CONTAINING)
        ->and($tuned->isIgnored('id'))->toBeTrue()
        ->and($tuned->includesNullValues())->toBeTrue();
});

it('lets a per-path matcher override the string matcher and the case setting', function () {
    $matcher = ExampleMatcher::matching()
        ->withIgnoreCase()
        ->withMatcher('email', GenericPropertyMatcher::startsWith()->caseSensitive());

    expect($matcher->isIgnoreCase('status'))->toBeTrue()
        ->and($matcher->isIgnoreCase('email'))->toBeFalse()
        ->and($matcher->stringMatcherFor('email'))->toBe(StringMatcher::STARTING)
        ->and($matcher->stringMatcherFor('status'))->toBe(StringMatcher::EXACT)
        ->and(GenericPropertyMatcher::contains()->ignoreCase()->ignoreCase)->toBeTrue()
        ->and(GenericPropertyMatcher::exact()->ignoreCase)->toBeNull();
});

it('escapes LIKE metacharacters with ! so a probe value is matched literally', function () {
    expect(StringMatcher::CONTAINING->pattern('50%_off!'))->toBe('%50!%!_off!!%')
        ->and(StringMatcher::STARTING->pattern('a'))->toBe('a%')
        ->and(StringMatcher::ENDING->pattern('a'))->toBe('%a')
        ->and(StringMatcher::EXACT->pattern('a%'))->toBe('a!%');
});

it('reads a model probe\'s set attributes and an array probe as-is, dropping ignored paths', function () {
    $fromModel = Example::of(new Record(['status' => 'open', 'amount' => 5]));
    $fromArray = Example::of(['status' => 'open', 'email' => null, 'amount' => 5], ExampleMatcher::matching()->withIgnorePaths('amount'));

    expect($fromModel->probe)->toBe(['status' => 'open', 'amount' => 5])
        ->and($fromModel->attributes())->toBe(['status' => 'open', 'amount' => 5])
        // null is dropped by default, `amount` by the ignore rule
        ->and($fromArray->attributes())->toBe(['status' => 'open'])
        ->and(Example::of(['email' => null], ExampleMatcher::matching()->withIncludeNullValues())->attributes())->toBe(['email' => null]);
});

it('refuses a probe key that is not a bare identifier, since the key reaches a raw fragment', function () {
    expect(fn () => Example::of(["email) = '' or 1=1 or (email" => 'x']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Example::of(['email' => 'x', 'a b' => 'y']))->toThrow(InvalidArgumentException::class);
});
