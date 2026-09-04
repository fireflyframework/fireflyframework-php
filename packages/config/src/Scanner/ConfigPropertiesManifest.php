<?php

declare(strict_types=1);

namespace Firefly\Config\Scanner;

use Firefly\Kernel\Exception\Framework\ConfigurationException;

final class ConfigPropertiesManifest
{
    /**
     * @param  list<ConfigPropertiesDescriptor>  $properties
     */
    public function __construct(public array $properties) {}

    /**
     * @param  array<int, array{class: string, prefix: string, profiles?: list<string>}>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(array_map(
            static fn (array $row): ConfigPropertiesDescriptor => ConfigPropertiesDescriptor::fromArray($row),
            array_values($data),
        ));
    }

    public static function load(string $path): self
    {
        if (! is_file($path)) {
            throw new ConfigurationException("Config-properties manifest not found at {$path}. Run the scan first.");
        }

        /** @var mixed $data */
        $data = require $path;
        if (! is_array($data)) {
            throw new ConfigurationException("Config-properties manifest at {$path} did not return an array.");
        }

        /** @var array<int, array{class: string, prefix: string, profiles?: list<string>}> $data */
        return self::fromArray($data);
    }
}
