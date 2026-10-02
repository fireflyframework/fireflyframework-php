<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Definition;

use Firefly\Kernel\Exception\Framework\ConfigurationException;

/**
 * A flag definition, or a flag document, broke a rule of the contract (spec §4.1, CONTRACT.md "Rules every
 * definition must satisfy"). The message always contains the contract's phrase for the rule, which both frameworks
 * emit and the vectors assert; a reason may add a hint after the phrase. flagKey() names the flag, or the document
 * part that broke a document-level rule (`flags`, `$evaluators`, `$evaluators.<name>`, `metadata`), or '' for the
 * document as a whole.
 */
final class InvalidFlagDefinition extends ConfigurationException
{
    public function __construct(
        private readonly string $flagKey,
        private readonly string $reason,
        private readonly ?string $source = null,
    ) {
        parent::__construct(
            sprintf('Invalid feature flag [%s]%s: %s.', $flagKey, $source === null ? '' : " from source [{$source}]", $reason),
            'INVALID_FEATURE_FLAG',
        );
    }

    public function flagKey(): string
    {
        return $this->flagKey;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function source(): ?string
    {
        return $this->source;
    }

    public function fromSource(string $source): self
    {
        return new self($this->flagKey, $this->reason, $source);
    }
}
