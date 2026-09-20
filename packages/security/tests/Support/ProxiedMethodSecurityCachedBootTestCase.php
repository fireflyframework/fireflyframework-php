<?php

declare(strict_types=1);

namespace Firefly\Security\Tests\Support;

use Firefly\AutoConfigure\Assembly\AutoConfigManifestCompiler;
use Firefly\Context\Scan\AppScan;
use Firefly\Data\Proxy\ProxyClassGenerator;
use Firefly\Data\Proxy\ProxyPlanCompiler;
use Firefly\Data\Proxy\ProxyPlanner;
use Firefly\Data\Proxy\TransactionalAdviceSource;
use Firefly\Data\Scanner\TransactionalScanner;
use Firefly\Data\Transaction\TransactionalManifestCompiler;
use Firefly\Security\Access\Method\MethodSecurityAdviceSource;
use Firefly\Security\Access\Method\SecurityMethodManifestCompiler;
use Firefly\Security\Scanner\MethodSecurityScanner;

/**
 * The CACHED half of ProxiedMethodSecurityBootTestCase: the same providers and the same `firefly.security.*`
 * tree, but NO `firefly.scan.paths` and `firefly.cache.path` pointing at a directory holding what `php artisan
 * firefly:cache` writes for tests/Fixtures/CachedAdvice — component.php, context.php, security-methods.php,
 * transactional.php, proxy-plan.php, proxies/<mangled>.php and the proxies.php classmap. With no scan roots
 * there is nothing to fall back to: the bean definitions can only have come from the compiled component
 * manifest, the rules from security-methods.php, and the proxy plan from proxy-plan.php — the transactional.php
 * bridge would plan nothing, since the fixtures carry no #[Transactional]. The fixtures are a namespace of their
 * own, not Advice/, because a proxy class is declared once per process (see CachedReportService).
 *
 * The artefacts are compiled inline with the SAME compilers firefly/cli's ManifestCacheWriter drives
 * (AutoConfigManifestCompiler; MethodSecurityScanner + SecurityMethodManifestCompiler; TransactionalScanner +
 * TransactionalManifestCompiler; a ProxyPlanner over TransactionalAdviceSource and MethodSecurityAdviceSource,
 * ProxyPlanCompiler, and ProxyClassGenerator::generate() per planned class with a var_export'd classmap),
 * because firefly/security cannot depend on firefly/cli. Before the writer planned through every AdviceSource
 * it emitted security-methods.php with every rule and a classmap naming only the #[Transactional] classes, and
 * DataAutoConfiguration::proxyPlan() bridged a transactional-only plan: the uncached boot the sibling base
 * takes enforced on ReportService while the cached one handed it out bare. This base is what turns that red.
 */
abstract class ProxiedMethodSecurityCachedBootTestCase extends ProxiedMethodSecurityBootTestCase
{
    private const string PROXY_DIR = 'proxies';

    /** @return array<string, mixed> */
    protected function configOverrides(): array
    {
        $dir = self::compiled();

        return [
            'firefly.cache.path' => $dir,
            'firefly.cache.component_manifest' => $dir.'/'.AppScan::COMPONENT,
            'firefly.cache.context_manifest' => $dir.'/'.AppScan::CONTEXT,
            'firefly.security' => $this->security(),
        ];
    }

    /** @return array<string, string> */
    public static function psr4(): array
    {
        return ['Firefly\\Security\\Tests\\Fixtures\\CachedAdvice\\' => dirname(__DIR__).'/Fixtures/CachedAdvice'];
    }

    /** The cache directory, written once per process — the artefacts are deterministic. */
    public static function compiled(): string
    {
        /** @var string|null $dir */
        static $dir = null;

        if ($dir !== null) {
            return $dir;
        }

        $dir = sys_get_temp_dir().'/firefly-security-proxy-cached-'.bin2hex(random_bytes(6));
        mkdir($dir.'/'.self::PROXY_DIR, 0o700, true);
        $psr4 = self::psr4();

        (new AutoConfigManifestCompiler)->write($psr4, $dir.'/'.AppScan::COMPONENT, $dir.'/'.AppScan::CONTEXT);
        (new SecurityMethodManifestCompiler)->write((new MethodSecurityScanner)->scan($psr4), $dir.'/'.AppScan::SECURITY_METHODS);
        (new TransactionalManifestCompiler)->write((new TransactionalScanner)->scan($psr4), $dir.'/'.AppScan::TRANSACTIONAL);

        $planner = new ProxyPlanner([new TransactionalAdviceSource, new MethodSecurityAdviceSource]);
        $plan = $planner->plan($psr4);
        (new ProxyPlanCompiler)->write($plan, $dir.'/'.AppScan::PROXY_PLAN);

        $generator = new ProxyClassGenerator;
        $map = [];
        foreach ($planner->proxyMethods($plan) as $target => $methods) {
            $file = $dir.'/'.self::PROXY_DIR.'/'.str_replace('\\', '_', $target).'.php';
            file_put_contents($file, $generator->generate($target, $methods));
            $map[$plan->proxyClassFor($target)] = $file;
        }
        file_put_contents($dir.'/'.AppScan::PROXY_MAP, "<?php\n\nreturn ".var_export($map, true).";\n");

        return $dir;
    }
}
