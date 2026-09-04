<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Schema;

use Firefly\Kernel\Error\ErrorCategory;
use Firefly\Kernel\Error\ErrorResponse;
use Firefly\Kernel\Error\ErrorSeverity;

/**
 * The error component every generated operation references — the shape LaraFly ACTUALLY returns, not the
 * shape RFC 7807 describes in the abstract.
 *
 * The distinction matters because the two differ. Firefly\Kernel\Error\ErrorResponse::toArray() emits
 * `status`/`title`/`code`/`category`/`severity` unconditionally, then `detail`/`type`/`instance`/`traceId`/
 * `timestamp` only when non-null, then `errors` only when non-empty; ProblemDetailsRenderer serialises that
 * under `Content-Type: application/problem+json`. So `code`, `category`, `severity` and `errors` are Firefly
 * extension members on top of RFC 7807's five (and RFC 9457's re-issue of them), `type` is OPTIONAL here
 * where the RFC gives it a default of `about:blank`, and `instance` carries a request PATH rather than a URI
 * reference. Documenting the RFC's shape instead of this one would hand every generated client a decoder that
 * silently drops the three members a caller actually branches on.
 *
 * `category` and `severity` are enumerated straight off ErrorCategory::cases()/ErrorSeverity::cases(), so a
 * new case added in packages/kernel appears in the spec on the next generation with no edit here — the same
 * reason RouteManifest, not a hand-kept list, is the source of the paths.
 *
 * FieldError::toArray() is mirrored by the nested `errors` item schema: `field`/`message` always, `code` and
 * `rejectedValue` only when non-null (hence not required). `rejectedValue` is deliberately untyped — it is
 * literally whatever the client sent.
 */
final class ProblemSchema
{
    public const string NAME = 'ProblemDetails';

    public const string REF = '#/components/schemas/'.self::NAME;

    public const string RESPONSE_NAME = 'Problem';

    public const string RESPONSE_REF = '#/components/responses/'.self::RESPONSE_NAME;

    public const string MEDIA_TYPE = 'application/problem+json';

    /**
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'title' => 'Problem Details',
            'description' => 'RFC 9457 problem details as rendered by '.ErrorResponse::class.'::toArray(), served as '.self::MEDIA_TYPE.'.',
            'required' => ['status', 'title', 'code', 'category', 'severity'],
            'properties' => [
                'status' => ['type' => 'integer', 'description' => 'The HTTP status code, repeated in the body.', 'minimum' => 100, 'maximum' => 599],
                'title' => ['type' => 'string', 'description' => 'A short, human-readable summary of the status.'],
                'code' => ['type' => 'string', 'description' => 'The stable, machine-readable Firefly error code (e.g. RESOURCE_NOT_FOUND).'],
                'category' => ['type' => 'string', 'description' => 'Firefly error category.', 'enum' => self::cases(ErrorCategory::cases())],
                'severity' => ['type' => 'string', 'description' => 'Firefly error severity.', 'enum' => self::cases(ErrorSeverity::cases())],
                'detail' => ['type' => 'string', 'description' => 'A human-readable explanation of this occurrence.'],
                'type' => ['type' => 'string', 'format' => 'uri-reference', 'description' => 'A URI reference identifying the problem type.'],
                'instance' => ['type' => 'string', 'description' => 'The request path this occurrence relates to.'],
                'traceId' => ['type' => 'string', 'description' => 'Correlation id for this occurrence, when tracing is active.'],
                'timestamp' => ['type' => 'string', 'format' => 'date-time', 'description' => 'When the error was rendered (ISO-8601).'],
                'errors' => [
                    'type' => 'array',
                    'description' => 'Field-level errors; present only on a validation failure.',
                    'items' => [
                        'type' => 'object',
                        'required' => ['field', 'message'],
                        'properties' => [
                            'field' => ['type' => 'string'],
                            'message' => ['type' => 'string'],
                            'code' => ['type' => 'string'],
                            'rejectedValue' => ['description' => 'The value that was rejected, as received.'],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function response(): array
    {
        return [
            'description' => 'Error response in RFC 9457 problem+json form.',
            'content' => [self::MEDIA_TYPE => ['schema' => ['$ref' => self::REF]]],
        ];
    }

    /**
     * @param  list<ErrorCategory>|list<ErrorSeverity>  $cases
     * @return list<string>
     */
    private static function cases(array $cases): array
    {
        return array_map(static fn (ErrorCategory|ErrorSeverity $case): string => $case->value, $cases);
    }
}
