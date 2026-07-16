<?php

declare(strict_types=1);

namespace Firefly\AutoConfigure;

use Firefly\Context\Definition\BeanDefinition;

/**
 * The single container-bound rendezvous between AutoConfiguration providers (which record candidacy at
 * register() time) and the bootstrap's phase-200/500 passes (which read it on the kernel's clock). It is an
 * unordered SET — provider-registration order (Laravel registers auto-discovered providers before app ones)
 * therefore cannot affect the result; deterministic precedence is imposed later by ConditionPassTwoPass's
 * (order, FQCN) sort at phase 600, never by insertion order here.
 */
final class AutoConfigurationCollector
{
    /** @var array<string, AutoConfigurationCandidate> keyed by provider FQCN for dedupe */
    private array $candidates = [];

    /** @var list<BeanDefinition> assembled at phase 200, drained at phase 500 */
    private array $assembled = [];

    public function add(AutoConfigurationCandidate $candidate): void
    {
        $this->candidates[$candidate->provider] = $candidate;
    }

    /**
     * @return list<AutoConfigurationCandidate>
     */
    public function all(): array
    {
        return array_values($this->candidates);
    }

    /**
     * @param  list<BeanDefinition>  $definitions
     */
    public function setAssembledDefinitions(array $definitions): void
    {
        $this->assembled = $definitions;
    }

    /**
     * @return list<BeanDefinition>
     */
    public function assembledDefinitions(): array
    {
        return $this->assembled;
    }
}
