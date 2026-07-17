<?php

declare(strict_types=1);

namespace Firefly\Web\Dispatch;

use Firefly\Web\Http\MessageConverterRegistry;
use Firefly\Web\Route\RouteDescriptor;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Content-negotiates a controller return. An already-built Response (Symfony OR Illuminate — incl.
 * JsonResponse) or a Responsable passes through untouched; any other value
 * (array/JsonSerializable/Arrayable/scalar) is written by the MessageConverter chosen from the request
 * Accept header and wrapped in a Response carrying $status if given, else the descriptor's default status.
 * Return type is the Symfony HttpFoundation Response supertype so a JsonResponse passthrough type-checks.
 */
final class ResponseFactory
{
    public function __construct(private readonly MessageConverterRegistry $converters) {}

    /**
     * @param  int|null  $status  status override for the negotiated body (e.g. a matched #[ExceptionHandler]
     *                            renders at the exception's httpStatus()); null = the descriptor's status.
     */
    public function make(mixed $result, RouteDescriptor $descriptor, Request $request, ?int $status = null): SymfonyResponse
    {
        if ($result instanceof SymfonyResponse) {
            return $result;
        }

        if ($result instanceof Responsable) {
            return $result->toResponse($request);
        }

        $accept = (string) $request->header('Accept', 'application/json');
        $converter = $this->converters->findWriter($accept);
        $mediaType = $converter?->mediaTypes()[0] ?? 'application/json';
        $body = $converter !== null
            ? $converter->write($result, $mediaType)
            : (string) json_encode($result, JSON_THROW_ON_ERROR);

        return new Response($body, $status ?? $descriptor->status, ['Content-Type' => $mediaType]);
    }
}
