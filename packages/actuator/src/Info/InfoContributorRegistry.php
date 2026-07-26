<?php

declare(strict_types=1);

namespace Firefly\Actuator\Info;

/** The ordered list of InfoContributors, populated at boot and read by InfoEndpoint. */
final class InfoContributorRegistry
{
    /** @var list<InfoContributor> */
    private array $contributors = [];

    public function register(InfoContributor $contributor): void
    {
        $this->contributors[] = $contributor;
    }

    /**
     * @return list<InfoContributor>
     */
    public function all(): array
    {
        return $this->contributors;
    }
}
