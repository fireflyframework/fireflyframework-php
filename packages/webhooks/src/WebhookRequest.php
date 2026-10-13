<?php

declare(strict_types=1);

namespace Firefly\Webhooks;

use Illuminate\Http\Request;

/**
 * One inbound webhook as the validators and the processor see it — the HTTP request reduced to what a signature can
 * cover. The URL is the EXACT one the sender signed: scheme + host + the raw request URI (Laravel's fullUrl() sorts
 * the query string, which breaks Twilio's signature), or the provider's configured public-url plus the query when a
 * proxy rewrites the host. Header names are lower-cased; a repeated header keeps its first value. The form
 * parameters are only read for application/x-www-form-urlencoded bodies, and they are parsed from the raw body the
 * sender signed — never from Laravel's request bag, which the global TrimStrings and ConvertEmptyStringsToNull
 * middleware have already rewritten (Twilio signs `Body=Hello ` with its trailing space) and which keeps only the last
 * value of a repeated name. Names are kept verbatim (no PHP `a.b` => `a_b` or `a[]` mangling); a name sent once maps
 * to its string, a repeated name to the list of its values in arrival order.
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

        $body = (string) $request->getContent();
        $form = $contentType === 'application/x-www-form-urlencoded' ? self::parseForm($body) : [];

        return new self($provider, $request->getMethod(), $url, $headers, $body, $form, $contentType, $now, $request->ip());
    }

    /**
     * Decodes an application/x-www-form-urlencoded body exactly as sent: `+` and percent escapes are decoded,
     * nothing is trimmed or nulled, and every value of a repeated name is kept.
     *
     * @return array<string, string|list<string>>
     */
    public static function parseForm(string $body): array
    {
        $values = [];
        foreach (explode('&', $body) as $pair) {
            if ($pair === '') {
                continue;
            }
            $parts = explode('=', $pair, 2);
            $values[urldecode($parts[0])][] = urldecode($parts[1] ?? '');
        }

        $form = [];
        foreach ($values as $name => $list) {
            $form[(string) $name] = count($list) === 1 ? $list[0] : $list;
        }

        return $form;
    }
}
