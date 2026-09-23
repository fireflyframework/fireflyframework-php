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

// A flexible column takes a share of what the rigid ones left, computed here rather than left to the
// browser: `table-layout:fixed` splits leftover width EVENLY between columns with no width, which is how a
// one-word Name column ends up as wide as a fully-qualified class name.
it('shares the remaining width between the flexible columns in proportion to their weight', function () {
    $view = TableView::of(
        TableColumn::pill('httpMethod', 'Method', ch: 7.5),
        TableColumn::path('path', 'Path', weight: 5),
        TableColumn::qualified('handler', 'Handler', weight: 4),
        TableColumn::token('name', 'Name', weight: 3),
    );

    expect($view->widths())->toBe([
        'calc(7.5ch + 2 * var(--row-x))',
        'calc((100% - (7.5ch + 1 * 2 * var(--row-x))) * 0.4167)',
        'calc((100% - (7.5ch + 1 * 2 * var(--row-x))) * 0.3333)',
        'calc((100% - (7.5ch + 1 * 2 * var(--row-x))) * 0.25)',
    ]);
});

it('subtracts every rigid column and every one of their paddings', function () {
    $view = TableView::of(
        TableColumn::pill('method', 'Method', ch: 7.5),
        TableColumn::number('status', 'Status', ch: 6),
        TableColumn::path('path', 'Path', weight: 1),
    );

    expect($view->widths()[2])->toBe('calc((100% - (13.5ch + 2 * 2 * var(--row-x))) * 1)');
});

it('gives an all-rigid table no percentage arithmetic at all', function () {
    $view = TableView::of(TableColumn::pill('a', 'A', ch: 4), TableColumn::number('b', 'B', ch: 6));

    expect($view->widths())->toBe(['calc(4ch + 2 * var(--row-x))', 'calc(6ch + 2 * var(--row-x))']);
});

it('gives an all-flexible table the whole width to share', function () {
    $view = TableView::of(TableColumn::text('key', 'Key', weight: 1), TableColumn::text('value', 'Value', weight: 3));

    expect($view->widths())->toBe([
        'calc((100% - (0ch + 0 * 2 * var(--row-x))) * 0.25)',
        'calc((100% - (0ch + 0 * 2 * var(--row-x))) * 0.75)',
    ]);
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
