<?php

declare(strict_types=1);

use Firefly\Data\Scanner\TransactionalScanner;
use Firefly\Data\Tests\Fixtures\Transactional\TransferService;
use Firefly\Data\Transaction\TransactionalManifest;
use Firefly\Data\Transaction\TransactionalManifestCompiler;
use Firefly\Kernel\Exception\Framework\ConfigurationException;

it('round-trips through the compiled, require-able manifest', function () {
    $manifest = (new TransactionalScanner)->scan(
        ['Firefly\\Data\\Tests\\Fixtures\\Transactional\\' => dirname(__DIR__).'/Fixtures/Transactional'],
    );

    $path = sys_get_temp_dir().'/firefly-tx-manifest-'.bin2hex(random_bytes(6)).'.php';

    try {
        (new TransactionalManifestCompiler)->write($manifest, $path);

        expect(TransactionalManifest::load($path)->toArray())->toBe($manifest->toArray())
            ->and(TransactionalManifest::load($path)->hasProxyFor(TransferService::class))->toBeTrue();
    } finally {
        @unlink($path);
    }
});

it('throws when the manifest file is missing', function () {
    expect(fn () => TransactionalManifest::load('/no/such/manifest.php'))->toThrow(ConfigurationException::class);
});

it('throws when asked for an unknown proxy class', function () {
    $manifest = new TransactionalManifest([], []);

    expect(fn () => $manifest->proxyClassFor('App\\Nope'))->toThrow(ConfigurationException::class);
});
