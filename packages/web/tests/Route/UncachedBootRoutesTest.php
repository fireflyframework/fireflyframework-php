<?php

declare(strict_types=1);

use Firefly\Web\Exception\ExceptionHandlerRegistry;
use Firefly\Web\Route\RouteManifest;
use Firefly\Web\Tests\Support\UncachedBootTestCase;

uses(UncachedBootTestCase::class);

it('scans #[RestController] routes in-process when nothing compiled a manifest', function () {
    /** @var UncachedBootTestCase $this */
    expect($this->app()->make(RouteManifest::class)->all())->not->toBeEmpty();
});

it('serves a scanned route over HTTP on a boot with no compiled cache', function () {
    /** @var UncachedBootTestCase $this */
    $this->get('/balances/7')
        ->assertStatus(200)
        ->assertExactJson(['id' => 7, 'amount' => '100.00']);
});

it('discovers #[ControllerAdvice] handlers in-process — they used to be dead in every real boot', function () {
    /** @var UncachedBootTestCase $this */
    expect($this->app()->make(ExceptionHandlerRegistry::class)->all())->not->toBeEmpty();
});
