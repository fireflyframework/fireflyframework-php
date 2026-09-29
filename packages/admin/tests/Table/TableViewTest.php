<?php

declare(strict_types=1);

use Firefly\Admin\Table\ColumnKind;
use Firefly\Admin\Table\TableColumn;
use Firefly\Admin\Table\TableView;

it('names each kind by the class the sheet styles it with', function () {
    expect(ColumnKind::Path->cssClass())->toBe('t-path')
        ->and(ColumnKind::Qualified->cssClass())->toBe('t-qual')
        ->and(ColumnKind::Pill->cssClass())->toBe('t-pill');
});

it('knows which kinds are sized from their own alphabet and which take a share of the rest', function () {
    expect(ColumnKind::Pill->isRigid())->toBeTrue()
        ->and(ColumnKind::Number->isRigid())->toBeTrue()
        ->and(ColumnKind::Stamp->isRigid())->toBeTrue()
        ->and(ColumnKind::Meter->isRigid())->toBeTrue()
        ->and(ColumnKind::Actions->isRigid())->toBeTrue()
        ->and(ColumnKind::Path->isRigid())->toBeFalse()
        ->and(ColumnKind::Qualified->isRigid())->toBeFalse()
        ->and(ColumnKind::Token->isRigid())->toBeFalse()
        ->and(ColumnKind::Text->isRigid())->toBeFalse()
        ->and(ColumnKind::Line->isRigid())->toBeFalse();
});

/**
 * THE PADDING IS IN THE WIDTH, AND THIS IS THE ASSERTION THAT KEEPS IT THERE. `box-sizing:border-box` is
 * global on this page, so `width:7.5ch` on a <col> is 7.5 characters INCLUDING both cell paddings — about
 * 34px of content on a 14px-padded cell, in which the verb DELETE clips. On the Routes page. Which is the
 * page this whole wave started from.
 */
it('emits a rigid width as characters plus both cell paddings', function () {
    $view = TableView::of(
        TableColumn::pill('httpMethod', 'Method', ch: 7.5),
        TableColumn::path('path', 'Path'),
    );

    expect($view->widths()[0])->toBe('calc(7.5ch + 2 * var(--row-x))');
});

/**
 * A FLEXIBLE COLUMN IS A PLAIN PERCENTAGE, AND THE ALTERNATIVE IS WHY THIS ASSERTION IS LITERAL. The
 * expression this once emitted — `calc((100% - (7.5ch + 1 * 2 * var(--row-x))) * 0.4167)` — is valid CSS
 * and is still not a width: a `<col>` whose `calc()` mixes a percentage with a SUBTRACTED length is not
 * resolvable by the fixed-layout algorithm, so Chromium falls back to `auto` and every flexible column
 * collapses to an identical share. Measured on this exact colgroup: 453/454/452 on a 1445px table, where
 * 5/4/3 asked for 567/454/340. A bare percentage needs no subtraction because the layout engine already
 * subtracts — it honours the declared lengths first and splits the REMAINDER between the percentage
 * columns in proportion to their percentages, which is 567/453/340 for the widths below.
 */
it('shares the remaining width between the flexible columns in proportion to their weight', function () {
    $view = TableView::of(
        TableColumn::pill('httpMethod', 'Method', ch: 7.5),
        TableColumn::path('path', 'Path', weight: 5),
        TableColumn::qualified('handler', 'Handler', weight: 4),
        TableColumn::token('name', 'Name', weight: 3),
    );

    expect($view->widths())->toBe([
        'calc(7.5ch + 2 * var(--row-x))',
        '41.6667%',
        '33.3333%',
        '25%',
    ]);
});

// The rigid columns are subtracted by the LAYOUT ENGINE, not here, so adding a second one changes what the
// flexible column is measured against without changing the percentage it declares. This is the assertion
// that stops the subtraction creeping back into the expression.
it('leaves the rigid columns for the layout engine to subtract', function () {
    $view = TableView::of(
        TableColumn::pill('method', 'Method', ch: 7.5),
        TableColumn::number('status', 'Status', ch: 6),
        TableColumn::path('path', 'Path', weight: 1),
    );

    expect($view->widths())->toBe([
        'calc(7.5ch + 2 * var(--row-x))',
        'calc(6ch + 2 * var(--row-x))',
        '100%',
    ]);
});

it('gives an all-rigid table no percentage arithmetic at all', function () {
    $view = TableView::of(TableColumn::pill('a', 'A', ch: 4), TableColumn::number('b', 'B', ch: 6));

    expect($view->widths())->toBe(['calc(4ch + 2 * var(--row-x))', 'calc(6ch + 2 * var(--row-x))']);
});

it('gives an all-flexible table the whole width to share', function () {
    $view = TableView::of(TableColumn::text('key', 'Key', weight: 1), TableColumn::text('value', 'Value', weight: 3));

    expect($view->widths())->toBe(['25%', '75%']);
});

// A weight of zero is a column that asked for nothing rather than a division by zero, and `0%` under a
// fixed layout is a column the engine gives no share of the remainder to — which is what was asked for.
it('gives a weightless flexible column no share instead of a division by zero', function () {
    $view = TableView::of(TableColumn::pill('a', 'A', ch: 4), TableColumn::text('b', 'B', weight: 0));

    expect($view->widths())->toBe(['calc(4ch + 2 * var(--row-x))', '0%']);
});

it('publishes its keys and the subset that may be sorted', function () {
    $view = TableView::of(
        TableColumn::pill('httpMethod', 'Method'),
        TableColumn::path('path', 'Path'),
        TableColumn::meter('Relative'),
        TableColumn::actions(),
    );

    expect($view->keys())->toBe(['httpMethod', 'path', '', ''])
        ->and($view->sortable())->toBe(['httpMethod', 'path']);
});

// A meter and an actions column have nothing to order BY — they are a picture of another column and a set
// of controls — so they are never sortable whatever a caller asks for.
it('refuses to make a meter or an actions column sortable', function () {
    expect(TableColumn::meter('Relative')->isSortable())->toBeFalse()
        ->and(TableColumn::actions('Set')->isSortable())->toBeFalse();
});

it('carries the separator a qualified name splits on, so a config key gets the same treatment as a class', function () {
    expect(TableColumn::qualified('class', 'Class')->separator)->toBe('\\')
        ->and(TableColumn::qualified('key', 'Key', separator: '.')->separator)->toBe('.')
        ->and(TableColumn::qualified('client', 'Client', separator: '')->separator)->toBe('');
});

/**
 * FITTING A HEADER ONLY EVER WIDENS. A rigid width is the alphabet the CELLS can hold, so taking the
 * greater of the two needs is the only composition that keeps both promises: `Id` over a bigint column
 * stays at the thirteen characters the values want, and `Failed login attempts` over the same column takes
 * the twenty-eight its header wants. 21 characters × 1.25 `ch` + 1.75 for the ordering indicator is 28.
 */
it('widens a rigid column to its own header and never narrows one', function () {
    $short = TableColumn::number('id', 'Id', ch: 13)->fittingItsHeader();
    $long = TableColumn::number('failed_login_attempts', 'Failed login attempts', ch: 13)->fittingItsHeader();

    expect($short->width)->toBe(13.0)
        ->and($long->width)->toBe(28.0)
        // Everything else about the column survives the widening.
        ->and($long->key)->toBe('failed_login_attempts')
        ->and($long->label)->toBe('Failed login attempts')
        ->and($long->kind)->toBe(ColumnKind::Number)
        ->and($long->isSortable())->toBeTrue();
});

// The indicator is a declared 10px box plus the anchor's 3px gap, paid whether the column is the sorted
// one or not — so an UNSORTABLE header of the same length needs 1.75ch less, and says so.
it('charges a sortable header for the ordering indicator and an unsortable one for nothing', function () {
    expect(TableColumn::number('n', 'Failed login attempts', ch: 13)->fittingItsHeader()->width)->toBe(28.0)
        ->and(TableColumn::number('n', 'Failed login attempts', ch: 13, sortable: false)->fittingItsHeader()->width)->toBe(26.25);
});

/**
 * A FLEXIBLE COLUMN'S `$width` IS A WEIGHT, so fitting it to a header would not widen a column — it would
 * enlarge that column's share of the leftovers at its neighbours' expense, for a reason that has nothing to
 * do with them. `Implements` at weight 3 would have become weight 12.5 and swallowed the table.
 */
it('leaves a flexible column alone, because its width is a weight and not a character count', function () {
    $text = TableColumn::text('interfaces', 'Implements', weight: 3);

    expect($text->fittingItsHeader())->toBe($text)
        ->and(TableColumn::path('path', 'Path', weight: 5)->fittingItsHeader()->width)->toBe(5.0);
});

// The stamp's nineteen characters hold `2026-09-22 20:49:26` and a header of up to fourteen; past that the
// header decides, which is the case the data browser's `Last signed in at` lands in.
it('lets a long header outgrow the alphabet a timestamp needs', function () {
    expect(TableColumn::stamp('created_at', 'Created at')->fittingItsHeader()->width)->toBe(19.0)
        ->and(TableColumn::stamp('last_signed_in_at', 'Last signed in at')->fittingItsHeader()->width)->toBe(23.0);
});
