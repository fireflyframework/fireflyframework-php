<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Generator;

use Firefly\OpenApi\Schema\DtoSchemaFactory;
use Firefly\OpenApi\Schema\ProblemSchema;
use Firefly\OpenApi\Schema\SchemaRegistry;
use Firefly\OpenApi\Schema\TypeSchema;
use Firefly\Web\Route\RouteDescriptor;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Turns one compiled RouteDescriptor into one OpenAPI Operation Object.
 *
 * Everything here comes out of the descriptor the framework already holds: the verb and path, the default
 * status the #[Mapping] declared, the optional route name, and the binding plan RouteScanner reflected out of
 * the controller method's parameters. The binding `kind` is what makes the mapping unambiguous, because it is
 * the SAME discriminator ArgumentResolver dispatches on at request time — `path`/`query`/`header` become
 * Parameter Objects, `body` becomes the Request Body Object, `file` becomes a multipart part, and `service`
 * is a container-injected collaborator that is not part of the HTTP contract at all and must not leak into
 * the document. Deriving the parameter list from the method signature independently would have to re-decide
 * every one of those cases and could disagree with the dispatcher; reading the plan cannot.
 *
 * @phpstan-import-type Binding from RouteDescriptor
 */
final class OperationFactory
{
    public function __construct(private readonly DtoSchemaFactory $schemas) {}

    /**
     * @return array<string, mixed>
     */
    public function create(RouteDescriptor $route, string $operationId, SchemaRegistry $registry): array
    {
        $parameters = [];
        $body = null;
        $files = [];
        $validated = false;
        $rejectable = false;

        foreach ($route->bindings as $binding) {
            $validated = $validated || $binding['valid'];

            switch ($binding['kind']) {
                case 'path':
                    $parameters[] = $this->parameter($binding, 'path', true);
                    $rejectable = $rejectable || $this->coercible($binding);
                    break;
                case 'query':
                    $parameters[] = $this->parameter($binding, 'query', $binding['required']);
                    $rejectable = $rejectable || $binding['required'] || $this->coercible($binding);
                    break;
                case 'header':
                    $parameters[] = $this->parameter($binding, 'header', $binding['required']);
                    $rejectable = $rejectable || $binding['required'] || $this->coercible($binding);
                    break;
                case 'file':
                    $files[] = $binding;
                    $rejectable = true;
                    break;
                case 'body':
                    $body = $binding;
                    $rejectable = true;
                    break;
            }
        }

        $operation = [
            'operationId' => $operationId,
            'summary' => $this->summary($route),
            'description' => 'Handled by '.$route->controllerClass.'::'.$route->methodName.'().',
            'tags' => [$this->tag($route)],
        ];

        if ($parameters !== []) {
            $operation['parameters'] = $parameters;
        }

        if ($body !== null) {
            $operation['requestBody'] = $this->requestBody($body, $registry);
        } elseif ($files !== []) {
            $operation['requestBody'] = $this->multipartBody($files);
        }

        $operation['responses'] = $this->responses($route, $rejectable, $validated);

        return $operation;
    }

    /**
     * A path/query/header value always arrives as a string on the wire; ArgumentResolver::coerce() is what
     * turns "42" into an int. The DECLARED type is still the right thing to publish — it is what the endpoint
     * accepts after coercion, and it is what a generated client should send — but a class type here has no
     * scalar spelling at all, so it degrades to `string` rather than becoming a dangling `$ref` to a
     * component that the request-body machinery never registered.
     *
     * `required` is passed in rather than read off the binding because a PATH parameter is required by the
     * OpenAPI specification itself (`required: false` is invalid there), independently of what the binding
     * plan happens to say — RouteScanner always plans one as required, and the caller reasserts it so the
     * document is valid by construction rather than by that coincidence.
     *
     * @param  Binding  $binding
     * @return array<string, mixed>
     */
    private function parameter(array $binding, string $in, bool $required): array
    {
        $schema = TypeSchema::for($binding['type']) ?? ['type' => 'string'];

        if ($binding['default'] !== null && is_scalar($binding['default'])) {
            $schema['default'] = $binding['default'];
        }

        return [
            'name' => $binding['key'],
            'in' => $in,
            'required' => $required,
            'schema' => $schema,
        ];
    }

    /**
     * @param  Binding  $binding
     * @return array<string, mixed>
     */
    private function requestBody(array $binding, SchemaRegistry $registry): array
    {
        $type = $binding['type'];

        $schema = $type !== null && TypeSchema::isDto($type)
            ? ['$ref' => $this->schemas->ref($type, $registry, $binding['properties'])]
            : ['type' => 'object'];

        return [
            'required' => true,
            'content' => ['application/json' => ['schema' => $schema]],
        ];
    }

    /**
     * A #[UploadedFile] parameter is not a JSON member — ArgumentResolver pulls it off the multipart request,
     * so the operation's body media type changes with it. Modelled as `format: binary`, which is how
     * OpenAPI 3.1 spells "raw bytes in a multipart part".
     *
     * @param  list<Binding>  $files
     * @return array<string, mixed>
     */
    private function multipartBody(array $files): array
    {
        $properties = [];
        $required = [];

        foreach ($files as $file) {
            $properties[$file['key']] = ['type' => 'string', 'format' => 'binary'];
            if ($file['required']) {
                $required[] = $file['key'];
            }
        }

        $schema = ['type' => 'object', 'properties' => $properties];
        if ($required !== []) {
            $schema['required'] = $required;
        }

        return [
            'required' => $required !== [],
            'content' => ['multipart/form-data' => ['schema' => $schema]],
        ];
    }

    /**
     * The success response plus every error status this operation can ACTUALLY produce, all pointing at the
     * one shared problem component.
     *
     * The error set is derived, not guessed. `400` appears exactly when the operation has something
     * ArgumentResolver can reject BEFORE the controller runs — a body to decode and bind (MALFORMED_BODY /
     * UNBINDABLE_BODY), an upload to validate (INVALID_UPLOAD), a required query/header the client may omit
     * (MISSING_PARAMETER), or a non-string parameter that has to be coerced out of the wire's string
     * (TYPE_CONVERSION_ERROR) — every one of which InvalidRequestException raises as 400. It is deliberately
     * NOT emitted for an operation whose only parameter is an optional `string` path variable: nothing about
     * such a request can fail binding (a missing path segment does not match the route at all), and a
     * documented 400 that the endpoint cannot produce is noise a generated client turns into a dead error
     * branch. `422` appears exactly when some binding carries #[Valid], because that is the only way
     * BeanValidator runs and so the only way kernel's ValidationException (fixed at 422, with its `errors`
     * array populated) can be thrown.
     * `default` covers everything the handler itself may raise — a 404 from a ResourceNotFoundException, a
     * 409 from a ConflictException, a 403 from a denied #[PreAuthorize] — which cannot be enumerated from the
     * route manifest without reading the controller's body, and which all render through the same
     * ProblemDetailsRenderer anyway.
     *
     * Keyed by `array-key` rather than `string` because PHP coerces a numeric string key to an INTEGER the
     * moment it is written — '201' becomes 201 — so the honest type for a status map is the mixed one. The
     * document is unaffected: a map keyed 201/400/'default' is not a PHP list, so json_encode still writes a
     * JSON object.
     *
     * @return array<array-key, mixed>
     */
    private function responses(RouteDescriptor $route, bool $rejectable, bool $validated): array
    {
        $responses = [(string) $route->status => $this->successResponse($route)];

        if ($rejectable) {
            $responses['400'] = ['$ref' => ProblemSchema::RESPONSE_REF];
        }

        if ($validated) {
            $responses['422'] = ['$ref' => ProblemSchema::RESPONSE_REF];
        }

        $responses['default'] = ['$ref' => ProblemSchema::RESPONSE_REF];

        return $responses;
    }

    /**
     * The success body, from the controller method's declared RETURN type — the only place the shape of a
     * successful response is stated anywhere in the framework, since RouteDescriptor records the status but
     * not the payload. A `204` (or a `void`/`never` return) gets no content at all, because emitting a
     * content map for a status that carries no body is exactly the sort of thing a strict client generator
     * turns into a phantom return type.
     *
     * `array` is the common LaraFly return and deliberately degrades to `type: object` rather than being
     * expanded from the method's `@return array{...}` docblock: parsing a PHPDoc array shape here would make
     * the generated document depend on comment text that nothing else in the framework treats as binding.
     *
     * @return array<string, mixed>
     */
    private function successResponse(RouteDescriptor $route): array
    {
        $type = $this->returnType($route);

        if ($route->status === 204 || $type === 'void' || $type === 'never') {
            return ['description' => 'No content.'];
        }

        // A #[Controller] route renders a page. It reaches this factory only when
        // firefly.openapi.include-html is on, and describing its response as a JSON schema would be a lie
        // that a client generator would faithfully act on.
        if ($route->html) {
            return [
                'description' => 'An HTML page.',
                'content' => ['text/html' => ['schema' => ['type' => 'string']]],
            ];
        }

        $schema = match (true) {
            $type === null => [],
            $type === 'array', $type === 'iterable' => ['type' => 'object'],
            default => TypeSchema::for($type) ?? ['type' => 'object'],
        };

        return [
            'description' => 'Successful response.',
            'content' => ['application/json' => ['schema' => $schema]],
        ];
    }

    /**
     * Whether this binding's value has to be CONVERTED out of the string the wire always carries — the
     * TYPE_CONVERSION_ERROR half of the 400 above. A `string` parameter needs no conversion and so cannot
     * fail one; anything else (int, float, bool, an enum) can.
     *
     * @param  Binding  $binding
     */
    private function coercible(array $binding): bool
    {
        return $binding['type'] !== null && $binding['type'] !== 'string';
    }

    private function returnType(RouteDescriptor $route): ?string
    {
        if (! class_exists($route->controllerClass) || ! method_exists($route->controllerClass, $route->methodName)) {
            return null;
        }

        $type = (new ReflectionMethod($route->controllerClass, $route->methodName))->getReturnType();

        return $type instanceof ReflectionNamedType ? $type->getName() : null;
    }

    /**
     * The tag a viewer groups this operation under: the controller's short name with a trailing "Controller"
     * removed, so `Lumen\Web\WalletController` reads as "Wallet".
     */
    private function tag(RouteDescriptor $route): string
    {
        $class = $route->controllerClass;
        $short = str_contains($class, '\\') ? substr($class, (int) strrpos($class, '\\') + 1) : $class;

        return str_ends_with($short, 'Controller') && $short !== 'Controller'
            ? substr($short, 0, -strlen('Controller'))
            : $short;
    }

    /**
     * `getBalance` reads as "Get balance". A method name is the only human-authored label a route carries
     * (a #[Mapping]'s name is a Laravel route name, not prose), so it is the honest source for a summary.
     */
    private function summary(RouteDescriptor $route): string
    {
        $words = preg_split('/(?=[A-Z])/', $route->methodName);
        $sentence = strtolower(trim(implode(' ', $words === false ? [$route->methodName] : $words)));

        return $sentence === '' ? $route->methodName : ucfirst($sentence);
    }
}
