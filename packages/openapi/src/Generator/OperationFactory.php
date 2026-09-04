<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Generator;

use Firefly\OpenApi\Attributes\ApiParameter;
use Firefly\OpenApi\Attributes\ApiResponse;
use Firefly\OpenApi\Schema\DocType;
use Firefly\OpenApi\Schema\DtoSchemaFactory;
use Firefly\OpenApi\Schema\ElementTypes;
use Firefly\OpenApi\Schema\ProblemSchema;
use Firefly\OpenApi\Schema\ResponseSchemaFactory;
use Firefly\OpenApi\Schema\SchemaRegistry;
use Firefly\OpenApi\Schema\TypeSchema;
use Firefly\Web\Route\RouteDescriptor;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Turns one compiled RouteDescriptor into one OpenAPI Operation Object.
 *
 * The MECHANICS come out of the descriptor the framework already holds: the verb and path, the default status
 * the #[Mapping] declared, the optional route name, and the binding plan RouteScanner reflected out of the
 * controller method's parameters. The binding `kind` is what makes the mapping unambiguous, because it is the
 * SAME discriminator ArgumentResolver dispatches on at request time — `path`/`query`/`header` become
 * Parameter Objects, `body` becomes the Request Body Object, `file` becomes a multipart part, and `service`
 * is a container-injected collaborator that is not part of the HTTP contract at all and must not leak into
 * the document. Deriving the parameter list from the method signature independently would have to re-decide
 * every one of those cases and could disagree with the dispatcher; reading the plan cannot.
 *
 * The PROSE comes from ApiDocs, which merges #[ApiOperation]/#[ApiResponse]/#[ApiParameter] over the method's
 * docblock over a derivation from the method name — in that order, decided there and not re-decided here. The
 * summary this factory writes used to be `ucfirst()` of the humanised method name and the description used to
 * be the literal string "Handled by App\Web\OrderController::show().", which was a placeholder wearing
 * documentation's clothes: it filled the slot a viewer renders, so nothing looked missing, while telling a
 * reader strictly less than an empty string would have. An absent description is now absent.
 *
 * @phpstan-import-type Binding from RouteDescriptor
 */
final class OperationFactory
{
    public function __construct(
        private readonly DtoSchemaFactory $schemas,
        private readonly ResponseSchemaFactory $responses = new ResponseSchemaFactory,
    ) {}

    /**
     * $docs is threaded in rather than injected, for the same reason SchemaRegistry is: both are per-DOCUMENT
     * state that OpenApiGenerator creates inside build() and discards with it. Holding either as a
     * constructor dependency of a container SINGLETON would give a cache the lifetime of the process while
     * the thing it caches for lives one generation, and would leave this factory's #[Bean] signature — the
     * documented override point — carrying a collaborator no application would ever want to replace.
     *
     * @return array<string, mixed>
     */
    public function create(RouteDescriptor $route, string $operationId, SchemaRegistry $registry, ApiDocs $docs): array
    {
        $doc = $docs->operation($route);

        $parameters = [];
        $body = null;
        $files = [];
        $validated = false;
        $rejectable = false;

        foreach ($route->bindings as $binding) {
            $validated = $validated || $binding['valid'];

            switch ($binding['kind']) {
                case 'path':
                    $parameters[] = $this->parameter($binding, 'path', true, $doc->parameters[$binding['key']] ?? null);
                    $rejectable = $rejectable || $this->coercible($binding);
                    break;
                case 'query':
                    $parameters[] = $this->parameter($binding, 'query', $binding['required'], $doc->parameters[$binding['key']] ?? null);
                    $rejectable = $rejectable || $binding['required'] || $this->coercible($binding);
                    break;
                case 'header':
                    $parameters[] = $this->parameter($binding, 'header', $binding['required'], $doc->parameters[$binding['key']] ?? null);
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

        $operation = ['operationId' => $operationId, 'summary' => $doc->summary];

        if ($doc->description !== '') {
            $operation['description'] = $doc->description;
        }

        // Emitted only when true. `deprecated` defaults to false in the specification, so writing it out on
        // every live operation would add a line per operation to every generated file to say nothing.
        if ($doc->deprecated) {
            $operation['deprecated'] = true;
        }

        $operation['tags'] = $docs->tagNames($route);

        if ($parameters !== []) {
            $operation['parameters'] = $parameters;
        }

        if ($body !== null) {
            $operation['requestBody'] = $this->requestBody($body, $registry);
        } elseif ($files !== []) {
            $operation['requestBody'] = $this->multipartBody($files);
        }

        $operation['responses'] = $this->responseSet($route, $rejectable, $validated, $doc, $registry);

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
     * document is valid by construction rather than by that coincidence. An #[ApiParameter(required:)] is
     * honoured for query and header parameters and DROPPED for a path one, for exactly the same reason: an
     * author override may reshape the document but may not make it invalid.
     *
     * The override deliberately does NOT feed back into the 400 derivation below. That derivation states what
     * the SERVER does — ArgumentResolver rejects a missing required parameter before the controller runs —
     * and an attribute cannot change the server's behaviour by describing it differently.
     *
     * @param  Binding  $binding
     * @return array<string, mixed>
     */
    private function parameter(array $binding, string $in, bool $required, ?ApiParameter $enrichment): array
    {
        $schema = TypeSchema::for($binding['type']) ?? ['type' => 'string'];

        if ($binding['default'] !== null && is_scalar($binding['default'])) {
            $schema['default'] = $binding['default'];
        }

        $parameter = ['name' => $binding['key'], 'in' => $in];

        if ($enrichment !== null && trim($enrichment->description) !== '') {
            $parameter['description'] = trim($enrichment->description);
        }

        $parameter['required'] = $in === 'path' ? true : ($enrichment->required ?? $required);
        $parameter['schema'] = $schema;

        // The Parameter Object's own `example` member, which 3.1 kept. Its SCHEMA-level namesake is the one
        // 3.1 deprecated in favour of JSON Schema's `examples` array — see ApiProperty, which is on that side
        // of the line and spells it the other way round.
        if ($enrichment !== null && $enrichment->example !== null) {
            $parameter['example'] = $enrichment->example;
        }

        return $parameter;
    }

    /**
     * The binding's `dtos` table is handed down with it, and that is the whole of the fix for a body DTO that
     * documented `#[Valid] array $lines` as `Array<any>`. RouteScanner compiled that table so ArgumentResolver
     * could HYDRATE the nested payload without reflecting; passing it here means the schema is written from
     * the same statement of the payload's shape that the server binds against, for every class in the graph
     * rather than only the one at the top. The key is optional on a compiled binding — it is written only
     * when the DTO actually nests — so an absent one degrades to ElementTypes' own resolution rather than
     * silently dropping `items` again.
     *
     * @param  Binding  $binding
     * @return array<string, mixed>
     */
    private function requestBody(array $binding, SchemaRegistry $registry): array
    {
        $type = $binding['type'];

        $schema = $type !== null && TypeSchema::isDto($type)
            ? ['$ref' => $this->schemas->ref($type, $registry, $binding['properties'], [], new ElementTypes($binding['dtos'] ?? []))]
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
     * one shared problem component, plus whatever #[ApiResponse] declared on top.
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
     * #[ApiResponse] is where an author states the half that is provably underivable — WHICH of those handler
     * statuses are real and what each one means. It is applied LAST and overwrites, so putting real prose on
     * the success status is a one-line edit rather than a fight with the derivation.
     *
     * Keyed by `array-key` rather than `string` because PHP coerces a numeric string key to an INTEGER the
     * moment it is written — '201' becomes 201 — so the honest type for a status map is the mixed one. That
     * coercion is what makes the override work at all: a derived '201' and a declared 201 land on the same
     * key rather than producing two entries. The document is unaffected: a map keyed 201/400/'default' is not
     * a PHP list, so json_encode still writes a JSON object.
     *
     * @return array<array-key, mixed>
     */
    private function responseSet(RouteDescriptor $route, bool $rejectable, bool $validated, OperationDoc $doc, SchemaRegistry $registry): array
    {
        $responses = [(string) $route->status => $this->successResponse($route, $registry)];

        if ($rejectable) {
            $responses['400'] = ['$ref' => ProblemSchema::RESPONSE_REF];
        }

        if ($validated) {
            $responses['422'] = ['$ref' => ProblemSchema::RESPONSE_REF];
        }

        $responses['default'] = ['$ref' => ProblemSchema::RESPONSE_REF];

        foreach ($doc->responses as $declared) {
            $responses[(string) $declared->status] = $this->declaredResponse($declared, $registry, $this->method($route)?->getDeclaringClass());
        }

        return $this->sortStatuses($responses);
    }

    /**
     * One #[ApiResponse] as a Response Object. `description` is the only REQUIRED member of that object, and
     * the attribute makes it a required constructor argument for exactly that reason — a Response Object
     * without one is invalid, and defaulting it to '' would produce a document that validates as a technicality
     * and reads as a blank.
     *
     * An omitted `type` documents a BODILESS response, which is the honest shape for a 204 or a 304 and the
     * common case for the error statuses this attribute mostly documents — those render through
     * ProblemDetailsRenderer, whose shape the shared problem component already states.
     *
     * `type` is a full PHPDoc type EXPRESSION, not only a class or a scalar name: `'list<Shipment>'`,
     * `'array<string, Money>'` and `'?Consignment'` all resolve, through the same parser that reads a
     * `@return` line. A bare `'array'` is still honoured as `type: array`, where the DERIVED success
     * response degrades the same PHP type to `type: object` — not an inconsistency, but the difference
     * between a declared type that cannot say which it is and an author who has said.
     *
     * @param  ReflectionClass<object>|null  $declaring
     * @return array<string, mixed>
     */
    private function declaredResponse(ApiResponse $declared, SchemaRegistry $registry, ?ReflectionClass $declaring = null): array
    {
        $response = ['description' => $declared->description];

        if ($declared->type === null) {
            return $response;
        }

        // The controller is the context a short name in the attribute was written in — `#[ApiResponse(type:
        // 'list<Shipment>')]` means whatever `Shipment` means in that file's imports, exactly as it would in
        // a docblock three lines below. Without it only a fully-qualified name would resolve, which is the
        // one spelling nobody writes.
        $schema = DocType::schema($declared->type, fn (string $class): array => $this->responses->schema($class, $registry), $declaring)
            ?? TypeSchema::for($declared->type)
            ?? ['type' => 'object'];

        $response['content'] = ['application/json' => ['schema' => $schema]];

        return $response;
    }

    /**
     * Numeric statuses ascending, then any other named one, then `default` last.
     *
     * Without this an #[ApiResponse(404)] would land after `default` simply because it was applied later, and
     * a reader scanning a viewer's response list would meet the catch-all before the specific case. The order
     * is also what makes a regenerated document diff cleanly: response order would otherwise depend on the
     * order attributes happen to be written in above the method.
     *
     * @param  array<array-key, mixed>  $responses
     * @return array<array-key, mixed>
     */
    private function sortStatuses(array $responses): array
    {
        $numeric = [];
        $named = [];

        foreach ($responses as $status => $response) {
            if (is_int($status)) {
                $numeric[$status] = $response;
            } else {
                $named[$status] = $response;
            }
        }

        ksort($numeric);
        ksort($named);

        $default = $named['default'] ?? null;
        unset($named['default']);

        $sorted = [];
        foreach ($numeric as $status => $response) {
            $sorted[$status] = $response;
        }
        foreach ($named as $status => $response) {
            $sorted[$status] = $response;
        }
        if ($default !== null) {
            $sorted['default'] = $default;
        }

        return $sorted;
    }

    /**
     * The success body — the shape of what the action actually returns.
     *
     * WHAT THIS USED TO SAY, AND WHY IT WAS WRONG. Every success response in every generated document was
     * `{"type": "object"}`. A viewer renders that as an empty panel and a client generator turns it into
     * `any`, so the single most useful thing an API document can state — what you get back — was the one
     * thing this file did not state. The reasoning was that a `@return array{...}` is "comment text nothing
     * else in the framework treats as binding", and that had already stopped being true: RouteScanner reads
     * `@param list<X>` to compile the table ArgumentResolver HYDRATES from, so a docblock type expression is
     * exactly as binding as a declared type on the way in. PHPStan at level max checks these expressions
     * against the code on every build, which is what makes reading them safe: an out-of-date `@return` is a
     * failing gate, not a silent lie.
     *
     * THREE SOURCES, most specific first.
     *
     *   `@return` — the only place `array` can say what is IN it. `array{page: int, items: list<Order>}`
     *   becomes a real object schema with a `$ref` inside it. Prose after the type expression becomes the
     *   response description, which is the only response description an author ever actually writes.
     *
     *   The DECLARED return type — a class becomes a component `$ref` built from its wire shape (see
     *   ResponseSchemaFactory), a scalar becomes itself, a backed enum becomes its value set.
     *
     *   Neither — `type: object`, the old behaviour, kept for a bare `array` return with nothing said about
     *   it. That is a real state (`array` genuinely does not say list-or-map, and LaraFly actions
     *   overwhelmingly return maps) and it is now the FALLBACK rather than the answer.
     *
     * A 204, a `void`/`never` return and an HTML page keep their existing shapes: emitting a content map for
     * a status that carries no body is exactly what a strict client generator turns into a phantom return
     * type, and describing a rendered page as JSON would be a lie a generator would act on.
     *
     * @return array<string, mixed>
     */
    private function successResponse(RouteDescriptor $route, SchemaRegistry $registry): array
    {
        $method = $this->method($route);
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

        [$documented, $prose] = $this->documentedReturn($method, $registry);

        $schema = $documented ?? $this->declaredReturnSchema($type, $registry);

        return [
            'description' => $prose === '' ? 'Successful response.' : $prose,
            'content' => ['application/json' => ['schema' => $schema]],
        ];
    }

    /**
     * The `@return` line as a schema plus its trailing prose.
     *
     * A parsed expression is used only when it says more than the declared type already would: a bare
     * `@return array` or `@return array<string, mixed>` parses fine and means nothing, and letting it win
     * would replace a `$ref` with an empty object for every action whose author wrote the loosest possible
     * annotation. The prose is taken either way — it is a description of THIS response and does not depend
     * on whether the type expression was informative.
     *
     * @return array{0: array<string, mixed>|null, 1: string}
     */
    private function documentedReturn(?ReflectionMethod $method, SchemaRegistry $registry): array
    {
        $line = DocBlock::parse($method?->getDocComment())->returnLine();

        if ($line === null || $method === null) {
            return [null, ''];
        }

        [$schema, $prose] = DocType::split(
            $line,
            fn (string $class): array => $this->responses->schema($class, $registry),
            new ReflectionClass($method->getDeclaringClass()->getName()),
        );

        return [$this->informative($schema) ? $schema : null, $prose];
    }

    /**
     * @param  array<string, mixed>|null  $schema
     */
    private function informative(?array $schema): bool
    {
        if ($schema === null) {
            return false;
        }

        foreach (['properties', 'items', 'additionalProperties', '$ref', 'enum', 'anyOf', 'allOf', 'prefixItems'] as $key) {
            if (array_key_exists($key, $schema)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function declaredReturnSchema(?string $type, SchemaRegistry $registry): array
    {
        return match (true) {
            $type === null => [],
            $type === 'array', $type === 'iterable' => ['type' => 'object'],
            TypeSchema::isDto($type) => $this->responses->schema($type, $registry),
            default => TypeSchema::for($type) ?? ['type' => 'object'],
        };
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
        $type = $this->method($route)?->getReturnType();

        return $type instanceof ReflectionNamedType ? $type->getName() : null;
    }

    /**
     * The action, when this process can load it. A compiled route manifest outlives the class it names — a
     * controller can be deleted between `firefly:cache` and a hit on the spec route — so every reflective
     * read here is guarded rather than assumed, and an unloadable action simply documents less.
     */
    private function method(RouteDescriptor $route): ?ReflectionMethod
    {
        if (! class_exists($route->controllerClass) || ! method_exists($route->controllerClass, $route->methodName)) {
            return null;
        }

        return new ReflectionMethod($route->controllerClass, $route->methodName);
    }
}
