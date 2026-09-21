<?php

declare(strict_types=1);

namespace Firefly\Data\Tests\Support;

use Firefly\Data\DataServiceProvider;
use Firefly\Data\Tests\Fixtures\Chain\AuditInterceptor;
use Firefly\Testing\FireflyDatabaseTestCase;
use Illuminate\Foundation\Application;

/**
 * Boots the REAL kernel with the shipped DataServiceProvider over sqlite on the UNCACHED path — `firefly.scan.paths`
 * pointed at tests/Fixtures/Chain and `firefly.cache.path` at a directory holding nothing — which is the boot a
 * developer's first `artisan serve` takes. Nothing is compiled by hand and nothing is bound by hand except the
 * AuditInterceptor instance (see bindsAuditInterceptor()): the app scan registers ChainedLedger, AuditAdviceSource
 * and ChainTransactionConfiguration as definitions, DataAutoConfiguration::proxyPlan() collects BOTH advice
 * sources through Container::getAll(), ProxyMaterializer generates the two-advice proxy in-process, and the
 * TransactionalBeanPostProcessor resolves the audit link through the InterceptorRegistry when the container
 * builds the bean. The unit-level ProxyChainTest drives the same classes through ProxyFactory directly; this base
 * is the pipeline half.
 */
abstract class ChainBootTestCase extends FireflyDatabaseTestCase
{
    public AuditInterceptor $audit;

    /** @return list<class-string> */
    protected function fireflyProviders(): array
    {
        return [DataServiceProvider::class];
    }

    /** @return array<string, mixed> */
    protected function configOverrides(): array
    {
        return [
            'firefly.scan.paths' => ['Firefly\\Data\\Tests\\Fixtures\\Chain\\' => dirname(__DIR__).'/Fixtures/Chain'],
            // A directory that holds no artifact, so every cachedFile() probe answers null and the scan branch
            // is the one under test — never whatever an earlier test in this process happened to compile.
            'firefly.cache.path' => sys_get_temp_dir().'/firefly-chain-uncached-'.bin2hex(random_bytes(6)),
        ];
    }

    /**
     * Whether the AuditInterceptor is bound before boot. AuditInterceptor is deliberately NOT a #[Component]:
     * the bound case binds this instance (so the test can read its log), and the unbound case binds nothing,
     * which is how a capability's interceptor is absent when the capability is switched off.
     */
    protected function bindsAuditInterceptor(): bool
    {
        return true;
    }

    protected function defineFireflyEnvironment(Application $app): void
    {
        $this->audit = new AuditInterceptor;

        if ($this->bindsAuditInterceptor()) {
            $app->instance(AuditInterceptor::class, $this->audit);
        }
    }
}
