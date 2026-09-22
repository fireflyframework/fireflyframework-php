<?php

declare(strict_types=1);

namespace Firefly\Data\Tests\Support;

use Firefly\AutoConfigure\Assembly\AutoConfigManifestCompiler;
use Firefly\Context\Scan\AppScan;
use Firefly\Data\DataServiceProvider;
use Firefly\Data\Proxy\ProxyClassGenerator;
use Firefly\Data\Scanner\TransactionalScanner;
use Firefly\Data\Transaction\TransactionalManifestCompiler;
use Firefly\Testing\FireflyDatabaseTestCase;

/**
 * Boots the REAL kernel with the shipped DataServiceProvider over sqlite the way a skeleton application boots
 * after a `php artisan firefly:cache` from BEFORE proxy-plan.php existed: `firefly.scan.paths` SET (the skeleton
 * ships `['App\\' => app_path()]`) and the cache directory holding component.php, context.php, transactional.php,
 * proxies/<mangled>.php and the proxies.php classmap — but NO proxy-plan.php. Today's writer emits the plan
 * (packages/cli's CachedBootTest covers that path); this base pins the bridge that keeps the older cache booting.
 *
 * The artifacts are compiled inline with the same compilers firefly/cli's ManifestCacheWriter drives
 * (AutoConfigManifestCompiler, TransactionalScanner + TransactionalManifestCompiler, scanProxyMethods() +
 * ProxyClassGenerator::generate() and a var_export'd classmap), because firefly/data cannot depend on firefly/cli.
 * The boot under test must then take the cached branch of BOTH data beans — TransactionalManifest::load() and the
 * transactional.php bridge in DataAutoConfiguration::proxyPlan() — and never the scan.
 */
abstract class CachedBootTestCase extends FireflyDatabaseTestCase
{
    /** @return list<class-string> */
    protected function fireflyProviders(): array
    {
        return [DataServiceProvider::class];
    }

    /** @return array<string, mixed> */
    protected function configOverrides(): array
    {
        $dir = self::compiled();

        return [
            'firefly.scan.paths' => self::psr4(),
            'firefly.cache.path' => $dir,
            'firefly.cache.component_manifest' => $dir.'/'.AppScan::COMPONENT,
            'firefly.cache.context_manifest' => $dir.'/'.AppScan::CONTEXT,
        ];
    }

    /** @return array<string, string> */
    public static function psr4(): array
    {
        return ['Firefly\\Data\\Tests\\Fixtures\\Cached\\' => dirname(__DIR__).'/Fixtures/Cached'];
    }

    /** The cache directory, written once per process — the artifacts are deterministic. */
    public static function compiled(): string
    {
        /** @var string|null $dir */
        static $dir = null;

        if ($dir !== null) {
            return $dir;
        }

        $dir = sys_get_temp_dir().'/firefly-data-cached-boot-'.bin2hex(random_bytes(6));
        mkdir($dir.'/'.self::PROXY_DIR, 0o700, true);
        $psr4 = self::psr4();
        $scanner = new TransactionalScanner;

        (new AutoConfigManifestCompiler)->write($psr4, $dir.'/'.AppScan::COMPONENT, $dir.'/'.AppScan::CONTEXT);
        (new TransactionalManifestCompiler)->write($scanner->scan($psr4), $dir.'/'.AppScan::TRANSACTIONAL);

        $generator = new ProxyClassGenerator;
        $map = [];
        foreach ($scanner->scanProxyMethods($psr4) as $target => $methods) {
            $file = $dir.'/'.self::PROXY_DIR.'/'.str_replace('\\', '_', $target).'.php';
            file_put_contents($file, $generator->generate($target, $methods));
            $map[$target.'__FireflyTransactionalProxy'] = $file;
        }
        file_put_contents($dir.'/'.AppScan::PROXY_MAP, "<?php\n\nreturn ".var_export($map, true).";\n");

        return $dir;
    }

    private const string PROXY_DIR = 'proxies';
}
