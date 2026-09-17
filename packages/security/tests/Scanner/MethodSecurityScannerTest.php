<?php

declare(strict_types=1);

use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Security\Access\Method\SecurityMethodManifest;
use Firefly\Security\Access\Method\SecurityMethodManifestCompiler;
use Firefly\Security\Scanner\MethodSecurityScanner;
use Firefly\Security\Tests\Fixtures\SecuredController;
use Firefly\Security\Tests\MalformedFixtures\Injection\InjectionSecuredController;
use Firefly\Security\Tests\MalformedFixtures\PreAuthorize\BrokenPreAuthorizeController;
use Firefly\Security\Tests\MalformedFixtures\Secured\ApostropheSecuredController;

it('compiles method-security attributes into normalised expressions with param names', function () {
    $rules = (new MethodSecurityScanner)->scan(['Firefly\\Security\\Tests\\Fixtures\\' => __DIR__.'/../Fixtures']);
    $manifest = new SecurityMethodManifest($rules);

    $show = $manifest->ruleFor(SecuredController::class, 'show');
    expect($show?->expression)->toBe("hasPermission(#id, 'READ')")
        ->and($show?->params)->toBe(['id']);

    expect($manifest->ruleFor(SecuredController::class, 'destroy')?->expression)->toBe("hasAnyRole('ADMIN', 'STAFF')")
        ->and($manifest->ruleFor(SecuredController::class, 'store')?->expression)->toBe("hasAnyAuthority('orders:write')")
        ->and($manifest->ruleFor(SecuredController::class, 'unguarded'))->toBeNull();

    // A rule that carries no product code and no sentence compiles with neither, so the guard falls back to
    // ACCESS_DENIED and the framework's client sentence.
    expect($show?->code)->toBeNull()
        ->and($show?->message)->toBeNull();
});

it('compiles the product code and sentence a #[PreAuthorize] names, and round-trips them', function () {
    $rules = (new MethodSecurityScanner)->scan(['Firefly\\Security\\Tests\\Fixtures\\' => __DIR__.'/../Fixtures']);
    $manifest = new SecurityMethodManifest($rules);

    $start = $manifest->ruleFor(SecuredController::class, 'start');
    expect($start?->expression)->toBe("hasAnyRole('MANAGER', 'TENANT_ADMIN')")
        ->and($start?->code)->toBe('RUN_ROLE_REQUIRED')
        ->and($start?->message)->toBe('Only a manager may start a run.');

    $path = sys_get_temp_dir().'/firefly-security-methods-'.bin2hex(random_bytes(6)).'.php';
    try {
        (new SecurityMethodManifestCompiler)->write($rules, $path);
        $loaded = SecurityMethodManifest::load($path)->ruleFor(SecuredController::class, 'start');
        expect($loaded?->code)->toBe('RUN_ROLE_REQUIRED')
            ->and($loaded?->message)->toBe('Only a manager may start a run.');
    } finally {
        @unlink($path);
    }
});

it('loads a manifest compiled before rules carried a code or a sentence', function () {
    $manifest = SecurityMethodManifest::fromArray([
        ['class' => 'App\\Ctrl', 'method' => 'show', 'expression' => "hasRole('USER')", 'params' => ['id']],
    ]);

    expect($manifest->ruleFor('App\\Ctrl', 'show')?->code)->toBeNull()
        ->and($manifest->ruleFor('App\\Ctrl', 'show')?->message)->toBeNull();
});

it('round-trips through the compiled var_export manifest', function () {
    $rules = (new MethodSecurityScanner)->scan(['Firefly\\Security\\Tests\\Fixtures\\' => __DIR__.'/../Fixtures']);
    $path = sys_get_temp_dir().'/firefly-security-methods-'.bin2hex(random_bytes(6)).'.php';

    try {
        (new SecurityMethodManifestCompiler)->write($rules, $path);
        $loaded = SecurityMethodManifest::load($path);
        expect($loaded->ruleFor(SecuredController::class, 'show')?->params)->toBe(['id']);
    } finally {
        @unlink($path);
    }
});

it('fails loud with a ConfigurationException naming the offending Class::method for a malformed #[PreAuthorize] expression', function () {
    expect(fn () => (new MethodSecurityScanner)->scan([
        'Firefly\\Security\\Tests\\MalformedFixtures\\PreAuthorize\\' => __DIR__.'/../MalformedFixtures/PreAuthorize',
    ]))->toThrow(ConfigurationException::class, BrokenPreAuthorizeController::class.'::show');
});

it('fails loud with a ConfigurationException when a #[Secured] value with an apostrophe breaks the generated hasAnyAuthority() expression', function () {
    expect(fn () => (new MethodSecurityScanner)->scan([
        'Firefly\\Security\\Tests\\MalformedFixtures\\Secured\\' => __DIR__.'/../MalformedFixtures/Secured',
    ]))->toThrow(ConfigurationException::class, ApostropheSecuredController::class.'::show');
});

it('rejects a #[RolesAllowed]/#[Secured] value containing a quote even when the resulting expression would otherwise parse as VALID grammar (expression injection)', function () {
    // Isolated fixture directory containing ONLY InjectionSecuredController: the value
    // "X') or permitAll() or hasAnyRole('Y" would otherwise compile to the grammar-valid
    // `hasAnyRole('X') or permitAll() or hasAnyRole('Y')` — which parse() would happily accept
    // and which would ALWAYS grant access. The scanner must reject the raw value outright.
    expect(fn () => (new MethodSecurityScanner)->scan([
        'Firefly\\Security\\Tests\\MalformedFixtures\\Injection\\' => __DIR__.'/../MalformedFixtures/Injection',
    ]))->toThrow(ConfigurationException::class, InjectionSecuredController::class.'::show');
});
