<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Tests\Support;

use Firefly\Data\DataServiceProvider;
use Firefly\FeatureFlags\Definition\FlagDefinitions;
use Firefly\FeatureFlags\Definition\Json;
use Firefly\FeatureFlags\FeatureFlagsServiceProvider;
use Firefly\FeatureFlags\FeatureFlagsWiringProvider;
use Firefly\FeatureFlags\Registry\FlagRegistry;
use Firefly\Testing\FireflyTestCase;
use OpenFeature\implementation\provider\NoOpProvider;
use OpenFeature\OpenFeatureAPI;

/** The uncached developer boot: scan paths on the fixtures, proxies generated in-process. */
abstract class GatedBeansTestCase extends FireflyTestCase
{
    protected function fireflyProviders(): array
    {
        return [DataServiceProvider::class, FeatureFlagsServiceProvider::class, FeatureFlagsWiringProvider::class];
    }

    protected function configOverrides(): array
    {
        return [
            'cache.default' => 'array',
            'firefly.scan.paths' => ['Firefly\\FeatureFlags\\Tests\\Fixtures\\GatedBeans\\' => dirname(__DIR__).'/Fixtures/GatedBeans'],
            'firefly.cache.path' => sys_get_temp_dir().'/firefly-feature-flags-gating-'.bin2hex(random_bytes(6)),
            'firefly.feature-flags.enabled' => true,
            'firefly.feature-flags.flags' => ['new-checkout' => false, 'new-pricing' => false, 'reports' => true],
            'firefly.feature-flags.web.disabled-status' => $this->disabledStatus(),
        ];
    }

    protected function disabledStatus(): int
    {
        return 404;
    }

    /** @param array<string, mixed> $flags shorthand or flagd */
    public function overrideFlags(array $flags): void
    {
        /** @var FlagRegistry $registry */
        $registry = $this->fireflyContext()->get(FlagRegistry::class);
        $registry->overrideForTests(FlagDefinitions::parseDocument(['flags' => Json::object(FlagDefinitions::normalize($flags))]));
    }

    protected function tearDown(): void
    {
        OpenFeatureAPI::getInstance()->setProvider(new NoOpProvider);
        parent::tearDown();
    }
}
