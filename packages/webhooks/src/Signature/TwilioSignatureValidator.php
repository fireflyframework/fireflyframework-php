<?php

declare(strict_types=1);

namespace Firefly\Webhooks\Signature;

use Firefly\Webhooks\Exception\WebhookRejectedException;
use Firefly\Webhooks\WebhookRequest;

/**
 * Twilio (validator `twilio`): `X-Twilio-Signature: base64(HMAC-SHA1(auth token, <signed string>))`, where the
 * signed string is the full URL followed by every POST parameter, sorted by name, as name + value. A JSON callback
 * signs the URL alone and carries the body's SHA-256 in the `bodySHA256` query parameter, which is checked first.
 * The branch is chosen by the URL, as twilio-php's RequestValidator does, never by the Content-Type an attacker can
 * rewrite: a URL carrying `bodySHA256` always has its body checked; otherwise only a form-encoded request is signed
 * as URL + parameters, and any other request must have an empty body, since nothing would cover its bytes.
 * Twilio signs no timestamp: replays are stopped by the idempotency store keyed on `I-Twilio-Idempotency-Token`.
 * The URL must be the one Twilio called — set the provider's public-url behind a proxy that rewrites the host.
 */
final class TwilioSignatureValidator implements SignatureValidator
{
    public function __construct(
        private readonly string $authToken,
        private readonly string $header = 'X-Twilio-Signature',
    ) {}

    public function verify(WebhookRequest $request): void
    {
        $signature = $request->header($this->header);
        if ($signature === null || $signature === '') {
            throw WebhookRejectedException::signatureInvalid($request->provider, "the {$this->header} header is missing");
        }

        parse_str((string) parse_url($request->url, PHP_URL_QUERY), $query);
        $contentType = strtolower(trim(explode(';', $request->contentType)[0]));

        if (array_key_exists('bodySHA256', $query)) {
            $bodyHash = $query['bodySHA256'];
            if (! is_string($bodyHash) || ! hash_equals(hash('sha256', $request->body), strtolower($bodyHash))) {
                throw WebhookRejectedException::signatureInvalid($request->provider, 'the bodySHA256 parameter does not match the body');
            }
            $signed = $request->url;
        } elseif ($contentType === 'application/x-www-form-urlencoded') {
            $signed = self::signedForm($request->url, $request->form);
        } elseif ($request->body === '') {
            $signed = $request->url;
        } else {
            throw WebhookRejectedException::signatureInvalid($request->provider, 'a body that is neither form-encoded nor covered by bodySHA256 is not signed');
        }

        if (! hash_equals(base64_encode(hash_hmac('sha1', $signed, $this->authToken, true)), $signature)) {
            throw WebhookRejectedException::signatureInvalid($request->provider, 'the signature does not match the URL and parameters');
        }
    }

    /**
     * The URL followed by name + value for every parameter, names sorted. A repeated parameter contributes each
     * distinct value once, values sorted — what twilio-php's RequestValidator signs, so a multi-valued callback
     * verifies here exactly as it does there.
     *
     * @param  array<array-key, mixed>  $params
     */
    public static function signedForm(string $url, array $params): string
    {
        ksort($params, SORT_STRING);
        $signed = $url;
        foreach ($params as $name => $value) {
            $items = [];
            foreach (is_array($value) ? $value : [$value] as $item) {
                $items[] = is_scalar($item) ? (string) $item : '';
            }
            $items = array_unique($items);
            sort($items, SORT_STRING);
            foreach ($items as $item) {
                $signed .= $name.$item;
            }
        }

        return $signed;
    }
}
