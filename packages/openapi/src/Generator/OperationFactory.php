<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Generator;

use Firefly\OpenApi\Attributes\ApiParameter;
use Firefly\OpenApi\Attributes\ApiResponse;
use Firefly\OpenApi\Schema\DtoSchemaFactory;
use Firefly\OpenApi\Schema\ElementTypes;
use Firefly\OpenApi\Schema\ProblemSchema;
use Firefly\OpenApi\Schema\SchemaRegistry;
use Firefly\OpenApi\Schema\TypeSchema;
use Firefly\Web\Route\RouteDescriptor;
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
    public function __construct(private readonly DtoSchemaFactory $schemas) {}

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

        $operation['responses'] = $this->responses($route, $rejectable, $validated, $doc, $registry);

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
    private function responses(RouteDescriptor $route, bool $rejectable, bool $validated, OperationDoc $doc, SchemaRegistry $registry): array
    {
        $responses = [(string) $route->status => $this->successResponse($route)];

        if ($rejectable) {
            $responses['400'] = ['$ref' => ProblemSchema::RESPONSE_REF];
        }

        if ($validated) {
            $responses['422'] = ['$ref' => ProblemSchema::RESPONSE_REF];
        }

        $responses['default'] = ['$ref' => ProblemSchema::RESPONSE_REF];

        foreach ($doc->responses as $declared) {
            $responses[(string) $declared->status] = $this->declaredResponse($declared, $registry);
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
     * `array` is honoured here as `type: array`, where the DERIVED success response degrades the same PHP
     * type to `type: object`. That is not an inconsistency: a controller's `array` return type genuinely does
     * not say whether the payload is a list or a map (LaraFly controllers overwhelmingly return maps), so the
     * derivation cannot know — whereas an author who typed `type: 'array'` into an attribute has said which
     * one they meant.
     *
     * @return array<string, mixed>
     */
    private function declaredResponse(ApiResponse $declared, SchemaRegistry $registry): array
    {
        $response = ['description' => $declared->description];

        if ($declared->type === null) {
            return $response;
        }

        $schema = TypeSchema::isDto($declared->type)
            ? ['$ref' => $this->schemas->ref($declared->type, $registry)]
            : TypeSchema::for($declared->type) ?? ['type' => 'object'];

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
     * The success body, from the controller method's declared RETURN type — the only place the shape of a
     * successful response is stated anywhere in the framework, since RouteDescriptor records the status but
     * not the payload. A `204` (or a `void`/`never` return) gets no content at all, because emitting a
     * content map for a status that carries no body is exactly the sort of thing a strict client generator
     * turns into a phantom return type.
     *
     * `array` is the common LaraFly return and deliberately degrades to `type: object` rather than being
     * expanded from the method's `@return array{...}` docblock: parsing a PHPDoc array shape here would make
     * the generated document depend on comment text that nothing else in the framework treats as binding.
     * A method's PROSE is now read (see ApiDocs) and its TYPES are still not, which is the line — prose has
     * no other source and cannot mislead a client generator; a mistyped `@return` silently can.
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
}
