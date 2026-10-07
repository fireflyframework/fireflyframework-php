<?php

declare(strict_types=1);

use Firefly\FeatureFlags\Gating\BladeDirectives;
use Firefly\FeatureFlags\Tests\Support\GatedBeansTestCase;
use Illuminate\Support\Facades\Blade;

uses(GatedBeansTestCase::class);

beforeEach(function (): void {
    /** @var GatedBeansTestCase $this */
    BladeDirectives::register($this->app()->make('blade.compiler'));
    $this->overrideFlags(['on' => true, 'off' => false, 'flow' => 'v2']);
});

it('renders the branch the flag selects', function (string $template, string $expected): void {
    expect(trim(Blade::render($template)))->toBe($expected);
})->with([
    'enabled' => ["@featureflag('on') yes @else no @endfeatureflag", 'yes'],
    'disabled' => ["@featureflag('off') yes @else no @endfeatureflag", 'no'],
    'missing fails closed' => ["@featureflag('missing') yes @else no @endfeatureflag", 'no'],
    'missing with a default' => ["@featureflag('missing', true) yes @else no @endfeatureflag", 'yes'],
    'unless' => ["@unlessfeatureflag('off') shown @endfeatureflag", 'shown'],
    'else-if' => ["@featureflag('off') a @elsefeatureflag('on') b @else c @endfeatureflag", 'b'],
    'variant matches' => ["@featurevariant('flow', 'v2') new @else old @endfeaturevariant", 'new'],
    'variant differs' => ["@featurevariant('flow', 'v1') new @else old @endfeaturevariant", 'old'],
]);
