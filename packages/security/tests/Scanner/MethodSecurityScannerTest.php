<?php

declare(strict_types=1);

use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Security\Access\Method\SecurityMethodManifest;
use Firefly\Security\Access\Method\SecurityMethodManifestCompiler;
use Firefly\Security\Scanner\MethodSecurityScanner;
use Firefly\Security\Tests\Fixtures\SecuredController;
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
