<?php

declare(strict_types=1);

use Firefly\OpenApi\Generator\DocBlock;

/**
 * The parser, tested on the comment shapes that actually occur in a LaraFly controller rather than on a
 * grammar. Every case here was found by pointing the generator at real code and reading what came out; the
 *
 * one-line `/** @return T *\/` case in particular shipped as an operation summary reading
 * "@return array<string, mixed>" until this test existed.
 */
it('splits the first sentence off as the summary and keeps the rest as the description', function () {
    $doc = DocBlock::parse(<<<'DOC'
        /**
         * List the products in one category. Withdrawn lines are never included.
         *
         * The cursor is opaque and must be echoed back exactly.
         */
        DOC);

    expect($doc->summary)->toBe('List the products in one category.')
        ->and($doc->description)->toBe("Withdrawn lines are never included.\n\nThe cursor is opaque and must be echoed back exactly.")
        // prose() is the whole thing, uncut — what a tag or schema description wants.
        ->and($doc->prose())->toBe("List the products in one category. Withdrawn lines are never included.\n\nThe cursor is opaque and must be echoed back exactly.");
});

it('treats a single-sentence comment as all summary and no description', function () {
    $doc = DocBlock::parse('/** Cancel an order. */');

    expect($doc->summary)->toBe('Cancel an order.')
        ->and($doc->description)->toBe('');
});

it('unwraps the editor line breaks a hard-wrapped paragraph carries', function () {
    $doc = DocBlock::parse(<<<'DOC'
        /**
         * Reserve stock against a basket, holding it for a fixed
         * window so a shopper can pay without racing anyone else.
         */
        DOC);

    expect($doc->summary)->toBe('Reserve stock against a basket, holding it for a fixed window so a shopper can pay without racing anyone else.')
        ->and($doc->summary)->not->toContain("\n");
});

it('does not end the sentence on an internal-dot abbreviation or a decimal point', function () {
    expect(DocBlock::parse('/** Cancels an order, e.g. a draft one. Refunds are separate. */')->summary)
        ->toBe('Cancels an order, e.g. a draft one.')
        ->and(DocBlock::parse('/** Charges 1.5 percent. Rounded half up. */')->summary)
        ->toBe('Charges 1.5 percent.');
});

it('reads a one-line docblock that holds nothing but a tag as empty prose', function () {
    // The bug this pins: without the opener's trailing spaces being stripped, the tag line no longer starts
    // at column zero, is not recognised as a tag, and becomes the operation's summary.
    $doc = DocBlock::parse('/** @return array<string, mixed> */');

    expect($doc->isEmpty())->toBeTrue()
        ->and($doc->summary)->toBe('')
        ->and($doc->has('return'))->toBeTrue();
});

it('reports @deprecated by presence, with or without a reason', function () {
    expect(DocBlock::parse("/**\n * Gone soon.\n *\n * @deprecated\n */")->has('deprecated'))->toBeTrue()
        ->and(DocBlock::parse("/**\n * Gone soon.\n *\n * @deprecated use v2\n */")->has('deprecated'))->toBeTrue()
        ->and(DocBlock::parse('/** Alive. */')->has('deprecated'))->toBeFalse();
});

it('reads @param descriptions past a type expression that contains spaces', function () {
    $doc = DocBlock::parse(<<<'DOC'
        /**
         * @param  array<string, mixed>  $payload  the decoded request body
         * @param  int  $attempts  how many times to retry
         * @param  string  $untouched
         */
        DOC);

    expect($doc->params())->toBe([
        'payload' => 'the decoded request body',
        'attempts' => 'how many times to retry',
    ]);
});

it('joins a wrapped @param description rather than truncating it at the line break', function () {
    $doc = DocBlock::parse(<<<'DOC'
        /**
         * @param  string  $cursor  an opaque page cursor, echoed back exactly
         *                          as the previous response returned it
         */
        DOC);

    expect($doc->params()['cursor'])->toBe('an opaque page cursor, echoed back exactly as the previous response returned it');
});

it('is empty for an absent comment, which is what every getDocComment() returns as false', function () {
    expect(DocBlock::parse(false)->isEmpty())->toBeTrue()
        ->and(DocBlock::parse(null)->isEmpty())->toBeTrue()
        ->and(DocBlock::parse('')->isEmpty())->toBeTrue()
        ->and(DocBlock::parse(false)->params())->toBe([]);
});
