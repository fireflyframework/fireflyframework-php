<?php

declare(strict_types=1);

use Firefly\Actuator\Introspection\SensitiveValueMasker;

/**
 * The rule itself, tested once, where both /env and /configprops now get it from. Before it was extracted the
 * only coverage was an /env test that fed it two scalars — which is precisely why the array bypass survived.
 */
it('masks every spelling the rule names, case-insensitively and as a substring', function () {
    $masked = SensitiveValueMasker::mask([
        'password' => 'p', 'SECRET' => 's', 'apiToken' => 't', 'signing_key' => 'k',
        'credentials' => 'c', 'passwd' => 'w', 'host' => 'db.local', 'port' => 5432,
    ]);

    expect($masked)->toBe([
        'password' => '******', 'SECRET' => '******', 'apiToken' => '******', 'signing_key' => '******',
        'credentials' => '******', 'passwd' => '******', 'host' => 'db.local', 'port' => 5432,
    ]);
});

// The bug the audit found: the key decides FIRST, so a sensitive key masks its whole subtree instead of being
// descended into and having each leaf judged on its own harmless name.
it('masks a sensitive key that holds an array, without leaking its shape', function () {
    expect(SensitiveValueMasker::mask(['keys' => ['active' => 'A', 'previous' => 'B'], 'issuer' => 'auth']))
        ->toBe(['keys' => '******', 'issuer' => 'auth']);
});

it('recurses into a non-sensitive key and masks what it finds there', function () {
    expect(SensitiveValueMasker::mask(['datasource' => ['host' => 'db.local', 'password' => 'hunter2']]))
        ->toBe(['datasource' => ['host' => 'db.local', 'password' => '******']]);
});

// Integer keys can never match the pattern, so a list under a harmless key survives intact — the predicate is
// total over array-key rather than needing a separate "is this a list?" branch.
it('leaves a list under a harmless key alone', function () {
    expect(SensitiveValueMasker::mask(['hosts' => ['a.local', 'b.local']]))
        ->toBe(['hosts' => ['a.local', 'b.local']]);
});

it('masks a whole list held under a sensitive key', function () {
    expect(SensitiveValueMasker::mask(['tokens' => ['t1', 't2']]))->toBe(['tokens' => '******']);
});

it('leaves an empty tree empty', function () {
    expect(SensitiveValueMasker::mask([]))->toBe([]);
});
