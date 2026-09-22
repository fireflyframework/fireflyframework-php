<?php

declare(strict_types=1);

use Firefly\Cli\Tests\Command\OAuth2KeysCommandTestCase;
use Firefly\Cli\Tests\Support\ArtisanAssertions;
use Firefly\Security\OAuth2\Server\Jose\SigningKey;

uses(OAuth2KeysCommandTestCase::class);

it('writes an RSA private key with owner-only permissions and prints the kid and the setting to use', function () {
    /** @var OAuth2KeysCommandTestCase $this */
    $out = $this->outDir().'/rs256-'.bin2hex(random_bytes(4)).'.pem';

    ArtisanAssertions::outputContains($this->artisan('firefly:oauth2:keys', ['--out' => $out]), 0, 'FIREFLY_OAUTH2_SERVER_SIGNING_KEY=');

    $key = SigningKey::fromPem((string) file_get_contents($out), 'RS256');
    expect(is_file($out))->toBeTrue()
        ->and(fileperms($out) & 0o777)->toBe(0o600)
        ->and($key->canSign)->toBeTrue();
});

it('generates a P-256 key for ES256 and prints it with --print instead of writing', function () {
    /** @var OAuth2KeysCommandTestCase $this */
    ArtisanAssertions::outputContains($this->artisan('firefly:oauth2:keys', ['--algorithm' => 'ES256', '--print' => true]), 0, '-----BEGIN');
});

it('refuses to overwrite an existing file without --force, and overwrites with it', function () {
    /** @var OAuth2KeysCommandTestCase $this */
    $out = $this->outDir().'/existing-'.bin2hex(random_bytes(4)).'.pem';
    file_put_contents($out, 'keep');

    ArtisanAssertions::exitCode($this->artisan('firefly:oauth2:keys', ['--out' => $out]), 1);
    expect(file_get_contents($out))->toBe('keep');

    ArtisanAssertions::exitCode($this->artisan('firefly:oauth2:keys', ['--out' => $out, '--force' => true]), 0);
    expect((string) file_get_contents($out))->toContain('-----BEGIN');
});

it('refuses an algorithm it does not know', function () {
    /** @var OAuth2KeysCommandTestCase $this */
    ArtisanAssertions::exitCode($this->artisan('firefly:oauth2:keys', ['--algorithm' => 'HS256', '--print' => true]), 1);
});
