<?php

declare(strict_types=1);

namespace Firefly\Web\Dispatch;

use Error;
use Firefly\Validation\Constraint\BeanValidator;
use Firefly\Web\Exception\InvalidRequestException;
use Firefly\Web\Http\MessageConverterRegistry;
use Firefly\Web\Http\UploadedFile;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile as IlluminateUploadedFile;

/**
 * Binds a controller method's arguments from the Illuminate Request + route params using a descriptor's
 * pure-array binding plan. A #[Valid] body is validated via BeanValidator BEFORE the DTO is hydrated
 * (reflection-free, via named-argument unpacking of the compiled property plan). Missing required inputs and
 * uncoercible values raise InvalidRequestException (400).
 *
 * THE DEFECT THIS CLASS WAS REWRITTEN FOR — NESTED DTOs. Hydration used to be one line,
 * `new $type(...$named)`, fed from a flat list of the constructor's parameter NAMES. That is exactly right
 * while every parameter is a scalar and catastrophically wrong the moment a request DTO contains another
 * one. The #[Valid] cascade already handled the nested case properly (ConstraintScanner compiles
 * `beneficiary.postcode` as a dot-key and validates it), so the payload passed validation, arrived at the
 * constructor as a raw sub-ARRAY where an AddressPayload was declared, and died with a TypeError — rendered
 * to the client as a 500 whose `detail` read "...must be of type ...AddressPayload, array given, called in
 * /Users/<someone>/.../src/Dispatch/ArgumentResolver.php on line 144". A perfectly valid request produced a
 * server error, and the server error quoted an absolute filesystem path back over the wire.
 *
 * WHY THE FIX IS A PLAN AND NOT A REFLECTION CALL. Building `new AddressPayload(...)` needs to know that
 * $beneficiary is an AddressPayload, and PHP has exactly one way to read a parameter's type: reflection.
 * Reflecting HERE would put reflection on the per-request hot path and break the invariant
 * packages/web/tests/ReflectionFreeWebTest.php guards — RouteScanner is the one sanctioned reflection site
 * in this package, and it runs at cache time only. So the type information is COMPILED instead, into an
 * optional `dtos` key on the body binding: a table keyed by class, each row mapping a constructor parameter
 * to the class it is built from (null for a builtin) and whether the payload holds a LIST of that class.
 *
 * Keying by CLASS rather than nesting the plan inline is what makes arbitrary depth work. An inline tree has
 * to stop somewhere — ConstraintScanner's #[Valid] cascade stops after one level, guarded by an ancestor set,
 * because a self-referential DTO would otherwise expand forever. A class-keyed table has one row per class no
 * matter how the graph is shaped, so a DTO that points at itself is a single row and the descent is bounded
 * only by the depth of the payload the client actually sent (itself bounded by json_decode's depth limit).
 *
 * WHAT THE RESOLVER DOES WHEN THE PLAN CANNOT SAY. `dtos` is optional, and a binding compiled before the
 * scanner learned to emit it still carries only the flat name list — as does a plan for a type the scanner
 * could not describe (an interface, a union, an `array` with no element type in its docblock). In every one
 * of those cases the value reaches the constructor untouched and the constructor rejects it. That rejection
 * is caught and re-thrown as the framework's own 400: a request the framework cannot bind is a bad request,
 * and answering it with a 500 misattributes the fault and leaks internals while doing so.
 *
 * That catch is Error, not TypeError, and the width is deliberate rather than lazy. TypeError alone covers
 * the headline case (a sub-array against a class-typed parameter) and ArgumentCountError, but three sibling
 * failures raise a plain Error and would have kept right on 500ing: "Cannot instantiate enum" (class_exists()
 * answers TRUE for an enum, so an enum-typed property reaches `new` like any other class), "Cannot instantiate
 * abstract class", and "Unknown named parameter" from a plan that has drifted from the constructor it
 * describes. Every one of those is a request the framework cannot bind, which is the definition of the 400 it
 * now gets. The price is that a genuine fault raised from INSIDE a DTO constructor's body is relabelled as a
 * client error — accepted because the lexical scope of the try is a single `new`, LaraFly DTOs are promoted
 * properties with no body, and the original throwable is attached as `previous` so the log still carries the
 * whole trace.
 *
 * None of these messages quote the caught exception. A PHP TypeError names the declaring file and the
 * calling file, so echoing it is how the path leak above happened; the client is told the dotted property
 * path it sent instead, matching the dot-keys #[Valid] already reports field errors under, and the original
 * throwable rides along as `previous` for the logs.
 *
 * #[RequestHeader] and #[UploadedFile] were the same defect wearing different clothes: neither honoured the
 * plan's `required` flag and neither ran the coercion every other binding runs, so a missing required header
 * or a missing required upload reached the handler as null and blew up in the handler's own signature — a
 * 500, again, for what is plainly a malformed request. Both now go through the same required/coerce path as
 * a path or query binding.
 *
 * Error codes raised here: MISSING_PARAMETER, TYPE_CONVERSION_ERROR, MALFORMED_BODY, INVALID_REQUEST,
 * INVALID_UPLOAD and UNBINDABLE_BODY — all 400, all category Validation (see InvalidRequestException).
 *
 * @phpstan-type PropertyPlan array{class: string|null, list: bool}
 * @phpstan-type DtoShape array<string, PropertyPlan>
 * @phpstan-type BodyBinding array{name: string, kind: string, key: string, type: string|null, required: bool, default: mixed, valid: bool, properties: list<string>, dtos?: array<string, DtoShape>}
 */
final class ArgumentResolver
{
    public function __construct(
        private readonly MessageConverterRegistry $converters,
        private readonly BeanValidator $beanValidator,
    ) {}

    /**
     * BodyBinding is RouteDescriptor's Binding plus the optional `dtos` shape table. It is spelled out here
     * rather than imported-and-extended because PHPStan has no syntax for widening an imported array shape;
     * the two stay honest because ControllerDispatcher passes a list<Binding> straight into this parameter,
     * so any drift in the descriptor's shape fails at that call site.
     *
     * @param  list<BodyBinding>  $bindings
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
     * @param  BodyBinding  $binding
     */
    private function resolveOne(array $binding, Request $request, Container $container): mixed
    {
        return match ($binding['kind']) {
            'path' => $this->coerce($this->pathValue($binding, $request), $binding),
            'query' => $this->coerce($this->queryValue($binding, $request), $binding),
            'header' => $this->coerce($this->headerValue($binding, $request), $binding),
            'file' => $this->fileValue($binding, $request),
            'body' => $this->bodyValue($binding, $request),
            'service' => $container->make($binding['type'] ?? ''),
            default => throw new InvalidRequestException("Unknown binding kind {$binding['kind']}."),
        };
    }

    /**
     * @param  BodyBinding  $binding
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
     * @param  BodyBinding  $binding
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
     * A header is a request parameter like any other: absent + required is a 400, absent + optional falls
     * back to the attribute's default, and whatever is found is coerced to the handler parameter's type. It
     * previously did none of that — `header() ?? default` handed a raw string (or a null) straight to the
     * handler, so `#[RequestHeader] int $version` failed in the handler's signature rather than here.
     *
     * @param  BodyBinding  $binding
     */
    private function headerValue(array $binding, Request $request): mixed
    {
        $value = $request->header($binding['key']);
        if ($value === null) {
            if ($binding['required']) {
                throw new InvalidRequestException("Missing request header {$binding['key']}.", 'MISSING_PARAMETER');
            }

            return $binding['default'];
        }

        return $value;
    }

    /**
     * Uploads have three client-caused failure modes and all three used to end at the handler's signature or
     * deep inside UploadedFile::fromIlluminate() as a 500: the field was not sent at all, the field was sent
     * as a multi-file array against a single-file parameter, and the upload did not complete (an oversized
     * body, an interrupted POST). The last one matters most because it is the one a well-behaved client hits
     * by accident: getRealPath() on a failed upload is false, which fromIlluminate() reports as an
     * InfrastructureException — a 500 for a file that was simply too big.
     *
     * @param  BodyBinding  $binding
     */
    private function fileValue(array $binding, Request $request): ?UploadedFile
    {
        $file = $request->file($binding['key']);

        if (is_array($file)) {
            throw new InvalidRequestException(
                "Expected a single uploaded file for {$binding['key']}.",
                'TYPE_CONVERSION_ERROR',
            );
        }

        if (! $file instanceof IlluminateUploadedFile) {
            if ($binding['required']) {
                throw new InvalidRequestException("Missing uploaded file {$binding['key']}.", 'MISSING_PARAMETER');
            }

            return null;
        }

        if (! $file->isValid()) {
            throw new InvalidRequestException(
                "The upload for {$binding['key']} did not complete.",
                'INVALID_UPLOAD',
            );
        }

        return UploadedFile::fromIlluminate($file);
    }

    /**
     * @param  BodyBinding  $binding
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
            // property is not silently dropped to its constructor default. It runs BEFORE hydration so a
            // 422 with per-field errors always beats the 400 a structurally-unbindable body would raise.
            $this->beanValidator->validate($data, $binding['type']);
        }

        $type = $binding['type'];
        if ($type === null || ! class_exists($type)) {
            return $data;
        }

        return $this->hydrate($type, $data, $binding['dtos'] ?? [], $binding['properties'], '');
    }

    /**
     * Builds one DTO by named-argument unpacking. The compiled shape row, when there is one, is the
     * authority on BOTH which parameters exist and what each is built from; the flat name list is the
     * fallback for a plan compiled before shapes existed, and it can only pass values through untouched.
     *
     * @param  class-string  $class
     * @param  array<string,mixed>  $data
     * @param  array<string, DtoShape>  $shapes
     * @param  list<string>|null  $fallbackProperties  null for a nested DTO, whose only source is $shapes
     */
    private function hydrate(string $class, array $data, array $shapes, ?array $fallbackProperties, string $path): object
    {
        $shape = $shapes[$class] ?? null;
        if ($shape === null && $fallbackProperties === null) {
            // A nested class the shape table does not describe. Guessing the constructor from the payload's
            // own keys would build a different object from the one the developer declared, so the request is
            // refused rather than half-bound.
            throw $this->unbindable($path);
        }

        $named = [];
        if ($shape !== null) {
            foreach ($shape as $property => $plan) {
                if (array_key_exists($property, $data)) {
                    $named[$property] = $this->hydrateProperty($data[$property], $plan, $shapes, $this->join($path, $property));
                }
            }
        } else {
            foreach ($fallbackProperties as $property) {
                if (array_key_exists($property, $data)) {
                    $named[$property] = $data[$property];
                }
            }
        }

        try {
            return new $class(...$named);
        } catch (Error $e) {
            throw $this->unbindable($path, $e);
        }
    }

    /**
     * @param  PropertyPlan  $plan
     * @param  array<string, DtoShape>  $shapes
     */
    private function hydrateProperty(mixed $value, array $plan, array $shapes, string $path): mixed
    {
        $class = $plan['class'];

        // A builtin-typed property, or an explicit null on a nullable one, is handed over untouched: the
        // constructor's own declared type is the arbiter, and hydrate()'s catch turns its refusal into a 400.
        if ($class === null || $value === null) {
            return $value;
        }

        if (! class_exists($class)) {
            // An interface, an enum, a union the scanner reduced to a name, a class that no longer exists.
            throw $this->unbindable($path);
        }

        if (! $plan['list']) {
            return $this->hydrateElement($value, $class, $shapes, $path);
        }

        if (! is_array($value)) {
            throw $this->unbindable($path);
        }

        $items = [];
        /** @var mixed $element */
        foreach ($value as $key => $element) {
            $items[] = $this->hydrateElement($element, $class, $shapes, $path.'['.$key.']');
        }

        return $items;
    }

    /**
     * @param  class-string  $class
     * @param  array<string, DtoShape>  $shapes
     */
    private function hydrateElement(mixed $value, string $class, array $shapes, string $path): object
    {
        if (! is_array($value)) {
            throw $this->unbindable($path);
        }

        /** @var array<string,mixed> $value */
        return $this->hydrate($class, $value, $shapes, null, $path);
    }

    /**
     * The client hears the dotted path it sent and nothing else — never the caught throwable, whose message
     * carries the declaring and calling FILE PATHS of the failure. The original rides along as `previous` so
     * the log keeps the full story.
     */
    private function unbindable(string $path, ?\Throwable $previous = null): InvalidRequestException
    {
        return new InvalidRequestException(
            $path === ''
                ? 'Could not bind the request body.'
                : "Could not bind the request body at {$path}.",
            'UNBINDABLE_BODY',
            $previous,
        );
    }

    /**
     * Dot-joins a property onto its parent path, matching the dot-keys ConstraintScanner compiles a #[Valid]
     * cascade under — so a 400 from hydration names a field the same way a 422 from validation does.
     */
    private function join(string $parent, string $property): string
    {
        return $parent === '' ? $property : $parent.'.'.$property;
    }

    /**
     * @param  BodyBinding  $binding
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
     * @param  BodyBinding  $binding
     */
    private function toInt(mixed $value, array $binding): int
    {
        $result = filter_var($value, FILTER_VALIDATE_INT);

        return $result === false ? $this->conversionError($binding) : $result;
    }

    /**
     * @param  BodyBinding  $binding
     */
    private function toFloat(mixed $value, array $binding): float
    {
        $result = filter_var($value, FILTER_VALIDATE_FLOAT);

        return $result === false ? $this->conversionError($binding) : $result;
    }

    /**
     * @param  BodyBinding  $binding
     * @return never
     */
    private function conversionError(array $binding): mixed
    {
        throw new InvalidRequestException("Could not convert {$binding['name']} to {$binding['type']}.", 'TYPE_CONVERSION_ERROR');
    }
}
