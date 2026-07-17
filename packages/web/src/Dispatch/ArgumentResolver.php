<?php

declare(strict_types=1);

namespace Firefly\Web\Dispatch;

use Firefly\Validation\Constraint\BeanValidator;
use Firefly\Web\Exception\InvalidRequestException;
use Firefly\Web\Http\MessageConverterRegistry;
use Firefly\Web\Http\UploadedFile;
use Firefly\Web\Route\RouteDescriptor;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile as IlluminateUploadedFile;

/**
 * Binds a controller method's arguments from the Illuminate Request + route params using a descriptor's
 * pure-array binding plan. A #[Valid] body is validated via BeanValidator BEFORE the DTO is hydrated
 * (reflection-free, via named-argument unpacking of the compiled `properties` list). Missing required
 * inputs and uncoercible scalars raise InvalidRequestException (400).
 *
 * @phpstan-import-type Binding from RouteDescriptor
 */
final class ArgumentResolver
{
    public function __construct(
        private readonly MessageConverterRegistry $converters,
        private readonly BeanValidator $beanValidator,
    ) {}

    /**
     * @param  list<Binding>  $bindings
     * @return list<mixed>
     */
    public function resolve(array $bindings, Request $request, Container $container): array
    {
        $args = [];
        foreach ($bindings as $binding) {
            $args[] = $this->resolveOne($binding, $request, $container);
        }

        return $args;
    }

    /**
     * @param  Binding  $binding
     */
    private function resolveOne(array $binding, Request $request, Container $container): mixed
    {
        return match ($binding['kind']) {
            'path' => $this->coerce($this->pathValue($binding, $request), $binding),
            'query' => $this->coerce($this->queryValue($binding, $request), $binding),
            'header' => $request->header($binding['key']) ?? $binding['default'],
            'file' => $this->fileValue($binding, $request),
            'body' => $this->bodyValue($binding, $request),
            'service' => $container->make($binding['type'] ?? ''),
            default => throw new InvalidRequestException("Unknown binding kind {$binding['kind']}."),
        };
    }

    /**
     * @param  Binding  $binding
     */
    private function pathValue(array $binding, Request $request): mixed
    {
        $value = $request->route()?->parameter($binding['key']);
        if ($value === null && $binding['required']) {
            throw new InvalidRequestException("Missing path variable {$binding['key']}.", 'MISSING_PARAMETER');
        }

        return $value;
    }

    /**
     * @param  Binding  $binding
     */
    private function queryValue(array $binding, Request $request): mixed
    {
        if (! $request->query->has($binding['key'])) {
            if ($binding['required']) {
                throw new InvalidRequestException("Missing query parameter {$binding['key']}.", 'MISSING_PARAMETER');
            }

            return $binding['default'];
        }

        return $request->query($binding['key']);
    }

    /**
     * @param  Binding  $binding
     */
    private function fileValue(array $binding, Request $request): ?UploadedFile
    {
        $file = $request->file($binding['key']);

        return $file instanceof IlluminateUploadedFile ? UploadedFile::fromIlluminate($file) : null;
    }

    /**
     * @param  Binding  $binding
     */
    private function bodyValue(array $binding, Request $request): mixed
    {
        $reader = $this->converters->findReader((string) $request->header('Content-Type', 'application/json'));
        if ($reader === null) {
            throw new InvalidRequestException('Unsupported request Content-Type.', 'INVALID_REQUEST');
        }

        try {
            /** @var mixed $decoded */
            $decoded = $reader->read($request->getContent(), (string) $binding['type'], (string) $request->header('Content-Type', 'application/json'));
        } catch (\JsonException $e) {
            // Malformed/empty client JSON is a CLIENT error, not a server fault: convert the converter's
            // JSON_THROW_ON_ERROR JsonException into a clean 400 instead of letting it surface as a 500.
            throw new InvalidRequestException('Malformed request body.', 'MALFORMED_BODY', $e);
        }
        if (! is_array($decoded)) {
            throw new InvalidRequestException('Malformed request body.', 'INVALID_REQUEST');
        }

        /** @var array<string,mixed> $data */
        $data = $decoded;

        if ($binding['valid'] && $binding['type'] !== null) {
            // Validation is the GATE ONLY: it throws on failure, and its validated() subset (fields that
            // carried a rule) is DISCARDED. The DTO is hydrated from the RAW body below so an unconstrained
            // property is not silently dropped to its constructor default.
            $this->beanValidator->validate($data, $binding['type']);
        }

        $type = $binding['type'];
        if ($type === null || ! class_exists($type)) {
            return $data;
        }

        $named = [];
        foreach ($binding['properties'] as $property) {
            if (array_key_exists($property, $data)) {
                $named[$property] = $data[$property];
            }
        }

        return new $type(...$named);
    }

    /**
     * @param  Binding  $binding
     */
    private function coerce(mixed $value, array $binding): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($binding['type']) {
            'int' => $this->toInt($value, $binding),
            'float' => $this->toFloat($value, $binding),
            'bool' => filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $this->conversionError($binding),
            default => $value,
        };
    }

    /**
     * @param  Binding  $binding
     */
    private function toInt(mixed $value, array $binding): int
    {
        $result = filter_var($value, FILTER_VALIDATE_INT);

        return $result === false ? $this->conversionError($binding) : $result;
    }

    /**
     * @param  Binding  $binding
     */
    private function toFloat(mixed $value, array $binding): float
    {
        $result = filter_var($value, FILTER_VALIDATE_FLOAT);

        return $result === false ? $this->conversionError($binding) : $result;
    }

    /**
     * @param  Binding  $binding
     * @return never
     */
    private function conversionError(array $binding): mixed
    {
        throw new InvalidRequestException("Could not convert {$binding['name']} to {$binding['type']}.", 'TYPE_CONVERSION_ERROR');
    }
}
