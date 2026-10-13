<?php

declare(strict_types=1);

namespace Firefly\Webhooks\Http;

use Firefly\Kernel\Exception\FireflyException;
use Firefly\Web\Exception\ProblemDetailsRenderer;
use Firefly\Webhooks\WebhookProcessor;
use Firefly\Webhooks\WebhookRequest;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * POST {firefly.webhooks.path}/{provider}. Every refusal is rendered HERE by the web layer's ProblemDetailsRenderer
 * rather than left to the exception handler: webhook senders rarely send `Accept: application/json`, and the
 * handler's HTML/JSON negotiation would hand Stripe or GitHub an HTML error page. A 5xx — a publish failure, or
 * anything unexpected — is also reported to the exception handler, so it reaches the logs like any other server error.
 */
final class WebhookController
{
    public function __construct(
        private readonly WebhookProcessor $processor,
        private readonly ProblemDetailsRenderer $problems,
        private readonly ExceptionHandler $exceptions,
    ) {}

    public function __invoke(Request $request, string $provider): Response
    {
        try {
            $settings = $this->processor->provider($provider);
            $result = $this->processor->process(WebhookRequest::fromHttp($request, $provider, $settings->publicUrl, time()));

            return new JsonResponse($result->toArray(), $result->httpStatus());
        } catch (Throwable $e) {
            if (! $e instanceof FireflyException || $e->httpStatus() >= 500) {
                $this->exceptions->report($e);
            }

            return $this->problems->render($e, $request);
        }
    }
}
