<?php

declare(strict_types=1);

namespace Firefly\Config\Scanner;

final readonly class ConfigPropertiesDescriptor
{
    /**
     * @param  list<string>  $profiles  the #[Profile] names the DTO declares, or [] when it declares
     *                                  none — read once by ConfigPropertiesScanner and acted on by
     *                                  ConfigRegistrar, so #[Profile] is no longer inert metadata
     */
    public function __construct(
        public string $class,
        public string $prefix,
        public array $profiles = [],
    ) {}

    /**
     * The compiled-manifest row.
     *
     * `profiles` is OMITTED when empty rather than written as `[]`. That is not micro-optimization:
     * the overwhelming majority of DTOs are unconstrained, and emitting the key unconditionally
     * would rewrite every single row of every already-committed config-properties.php the first time
     * an application re-ran firefly:cache, turning a behaviour-preserving upgrade into a large and
     * completely uninformative diff. Omitting it keeps the artifact byte-identical for every DTO
     * that has no profile requirement.
     *
     * @return array{class: string, prefix: string, profiles?: list<string>}
     */
    public function toArray(): array
    {
        $row = ['class' => $this->class, 'prefix' => $this->prefix];

        if ($this->profiles !== []) {
            $row['profiles'] = $this->profiles;
        }

        return $row;
    }

    /**
     * `profiles` is read with a default rather than required, so a config-properties.php compiled by
     * an older firefly:cache — or by an older release of firefly/cli, which versions separately —
     * still loads, as "no requirement". An upgrade must never have to be sequenced with a cache
     * rebuild to avoid an undefined-array-key fatal on boot.
     *
     * @param  array{class: string, prefix: string, profiles?: list<string>}  $d
     */
    public static function fromArray(array $d): self
    {
        return new self($d['class'], $d['prefix'], $d['profiles'] ?? []);
    }
}
