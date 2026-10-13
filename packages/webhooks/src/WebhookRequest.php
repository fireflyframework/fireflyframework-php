<?php

declare(strict_types=1);

namespace Firefly\Webhooks;

use Illuminate\Http\Request;

/**
 * One inbound webhook as the validators and the processor see it — the HTTP request reduced to what a signature can
 * cover. The URL is the EXACT one the sender signed: scheme + host + the raw request URI (Laravel's fullUrl() sorts
 * the query string, which breaks Twilio's signature), or the provider's configured public-url plus the query when a
 * proxy rewrites the host. Header names are lower-cased; a repeated header keeps its first value. The form
 * parameters are only read for application/x-www-form-urlencoded bodies.
 */
final readonly class WebhookRequest
{
    /**
     * @param  array<string, string>  $headers  lower-cased name => first value
     * @param  array<string, mixed>  $form
     */
    public function __construct(
        public string $provider,
        public string $method,
        public string $url,
        public array $headers,
        public string $body,
        public array $form,
        public string $contentType,
        public int $receivedAt,
        public ?string $sourceIp = null,
    ) {}

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public static function fromHttp(Request $request, string $provider, ?string $publicUrl, int $now): self
    {
        $headers = [];
        foreach ($request->headers->all() as $name => $values) {
            $first = $values[0] ?? null;
            if (is_string($first)) {
                $headers[strtolower((string) $name)] = $first;
            }
        }

        $uri = $request->getRequestUri();
        $query = ($position = strpos($uri, '?')) !== false ? substr($uri, $position) : '';
        $url = $publicUrl !== null ? rtrim($publicUrl, '/').$query : $request->getSchemeAndHttpHost().$uri;

        $contentType = strtolower(trim(explode(';', (string) $request->headers->get('Content-Type', ''))[0]));

        $form = [];
        if ($contentType === 'application/x-www-form-urlencoded') {
            foreach ($request->request->all() as $name => $value) {
                $form[(string) $name] = $value;
            }
        }

        return new self($provider, $request->getMethod(), $url, $headers, (string) $request->getContent(), $form, $contentType, $now, $request->ip());
    }
}
