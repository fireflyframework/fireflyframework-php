<?php

declare(strict_types=1);

namespace Firefly\Config\Binder;

interface ConfigBinder
{
    /**
     * @template T of object
     *
     * @param  class-string<T>  $class
     * @param  array<string,mixed>  $config
     * @return T
     */
    public function bind(string $class, array $config): object;
}
