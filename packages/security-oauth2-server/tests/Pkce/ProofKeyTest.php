<?php

declare(strict_types=1);

use Firefly\Security\OAuth2\Server\Pkce\ProofKey;

it('generates a 43-character base64url verifier whose S256 challenge verifies, constant-time', function () {
    $proof = ProofKey::generate();

    expect(strlen($proof->verifier))->toBe(43)
        ->and(preg_match('/^[A-Za-z0-9\-._~]+$/', $proof->verifier))->toBe(1)
        ->and($proof->challenge())->toBe(rtrim(strtr(base64_encode(hash('sha256', $proof->verifier, true)), '+/', '-_'), '='))
        ->and(ProofKey::verify($proof->verifier, $proof->challenge()))->toBeTrue()
        ->and(ProofKey::verify(ProofKey::generate()->verifier, $proof->challenge()))->toBeFalse()
        ->and(ProofKey::verify('', $proof->challenge()))->toBeFalse();
});

it('knows a well-formed value: 43 to 128 unreserved characters', function () {
    expect(ProofKey::isWellFormed(str_repeat('a', 43)))->toBeTrue()
        ->and(ProofKey::isWellFormed(str_repeat('a', 128)))->toBeTrue()
        ->and(ProofKey::isWellFormed(str_repeat('a', 42)))->toBeFalse()
        ->and(ProofKey::isWellFormed(str_repeat('a', 129)))->toBeFalse()
        ->and(ProofKey::isWellFormed(str_repeat('a', 42).'+'))->toBeFalse();
});
