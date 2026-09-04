<?php

declare(strict_types=1);

use Firefly\Config\Profile\Profiles;

it('reports active membership and lists all', function () {
    $p = new Profiles(['prod', 'eu']);

    expect($p->isActive('prod'))->toBeTrue()
        ->and($p->isActive('eu'))->toBeTrue()
        ->and($p->isActive('dev'))->toBeFalse()
        ->and($p->all())->toBe(['prod', 'eu'])
        ->and($p->isEmpty())->toBeFalse();
});

it('treats an empty set as empty', function () {
    expect((new Profiles([]))->isEmpty())->toBeTrue()
        ->and((new Profiles([]))->isActive('anything'))->toBeFalse();
});

/**
 * accepts() is THE profile predicate for the whole framework — the one place the question "may this
 * bean exist under the currently active profiles?" is answered. It is deliberately a method on the
 * Profiles value object rather than a rule re-implemented at each gate, because the alternative is
 * what the codebase actually had: a #[Profile] attribute that nothing consumed, and a separate
 * #[ConditionalOnProfile] evaluator in firefly/context that quietly owned the only working copy of
 * the semantics.
 */
it('accepts an unconstrained bean, whatever the active profiles', function () {
    expect((new Profiles(['prod']))->accepts([]))->toBeTrue()
        ->and((new Profiles([]))->accepts([]))->toBeTrue();
});

it('accepts a constrained bean when ANY one of its required profiles is active (OR, never AND)', function () {
    $profiles = new Profiles(['prod', 'eu']);

    expect($profiles->accepts(['prod']))->toBeTrue()
        ->and($profiles->accepts(['dev', 'prod']))->toBeTrue()
        ->and($profiles->accepts(['dev']))->toBeFalse()
        ->and($profiles->accepts(['dev', 'staging']))->toBeFalse();
});

it('rejects every constrained bean when no profile at all is active', function () {
    expect((new Profiles([]))->accepts(['prod']))->toBeFalse();
});
