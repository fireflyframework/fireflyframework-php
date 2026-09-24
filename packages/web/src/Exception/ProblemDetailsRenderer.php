<?php

declare(strict_types=1);

namespace Firefly\Web\Exception;

use BackedEnum;
use DateTimeImmutable;
use DateTimeInterface;
use Firefly\Kernel\Error\ErrorCategory;
use Firefly\Kernel\Error\ErrorResponse;
use Firefly\Kernel\Error\ErrorSeverity;
use Firefly\Web\Error\ErrorPageSettings;
use Firefly\Web\Error\ProblemMapper;
use Firefly\Web\Error\ProblemType;
use Firefly\Web\Filter\CorrelationIdFilter;
use Firefly\Web\Trace\TraceContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;
use UnitEnum;

/**
 * Renders any throwable as an application/problem+json response via ErrorResponse::fromException (a thin map
 * — status/category/severity live on the exception). Does NOT redefine the error shape (that is kernel/M1's
 * ErrorResponse), and no longer decides what a non-Firefly throwable BECOMES either: that rule moved to
 * ProblemMapper when the HTML error page started needing the same answer, because two copies of it would
 * eventually disagree about the same exception and hand a browser and a client different error codes for
 * one failure.
 *
 * THREE THINGS EVERY PROBLEM DOCUMENT NOW CARRIES THAT IT USED TO LACK:
 *
 *   - `traceId`, and the same value on `X-Correlation-Id`. CorrelationIdFilter mints or reads the id at
 *     order -100 and stamps it on every log line of the request; this renderer passed no traceId at all,
 *     so the body a person screenshotted and the log line an operator searched for had nothing in common.
 *     An opaque 5xx sentence names the reference too, so the one action a person can take is possible
 *     from the body alone.
 *   - The headers an HttpExceptionInterface carries. A 405's `Allow` header was set by the router on the
 *     exception and then dropped here, because only Content-Type was ever written on the response.
 *   - `Retry-After` on a 503. The two things that produce one — a starved worker pool, an unreachable
 *     upstream — clear in seconds when they clear at all, and the header is the standard way to say so.
 *   - `traceId` IS the W3C trace id now, and `correlationId` is its own member beside it. The first bullet
 *     above described the release in which `traceId` carried the correlation id: a member named after a
 *     trace holding a value no trace backend had ever heard of. TraceContext::referenceFor() publishes the
 *     request's W3C trace id when tracing is on and this request has a valid one, and FALLS BACK to the
 *     correlation id — byte for byte what this document carried before — when it does not, so the only
 *     thing that changes the value is switching tracing on. The correlation id is not absorbed: it keeps
 *     `X-Correlation-Id` untouched and gains `correlationId`, and the trace id is echoed on its own header
 *     (`firefly.web.trace-id.header`, `X-Trace-Id` by default, '' to disable) only when there is one.
 *   - A BODY, unconditionally. The encoder is total: an invalid UTF-8 byte is substituted rather than
 *     raised, and anything json_encode still refuses — or anything an encoded object's OWN jsonSerialize()
 *     or accessor throws, which JSON_THROW_ON_ERROR never sees and which catching JsonException therefore
 *     never caught — falls back to a minimal document. That document keeps every standard member, the
 *     reference among them, every field error's name and sentence, and every extension member's NAME, and
 *     drops only the values that can be the cause. This method used to throw JsonException out of the
 *     error handler on a latin-1 byte in a driver message, which turned a described failure into a blank
 *     500 with no document at all.
 *   - AND THE DEGRADATION SAYS SO, in the two places a person looks. An error-handling subsystem that
 *     fails INVISIBLY is the failure this whole surface exists to remove, and for one release the fallback
 *     above was exactly that: it caught a Throwable an application's own accessor had raised, dropped the
 *     open namespace on the floor, and published a document a healthy one could not be told apart from —
 *     same status, same code, same category, no marker, and not one log line anywhere in the process. A
 *     misconfigured application lost its extension members from every problem document it published, over
 *     and over, with no way for an operator to learn that it was happening or why. So: the caught
 *     throwable is REPORTED through the optional logger below, with the document's own reference in the
 *     context so the log line and the body a caller is holding join up; and the document itself NAMES what
 *     it could not carry, because minimal() renders an unencodable member as its type instead of deleting
 *     it — Firefly\Actuator\Introspection\ConfigPropsEndpoint::value() has answered the same question that
 *     way since it shipped, and saying `"balance": "App\Models\Balance"` is more use to everyone than a
 *     member that silently was not there.
 */
final class ProblemDetailsRenderer
{
    /** Seconds a caller is told to wait before retrying a 503. Short, for the reason in the class comment. */
    public const int RETRY_AFTER_SECONDS = 5;

    /**
     * How deep encodable() walks an extension member's arrays before it stops describing and starts naming.
     * ConfigPropsEndpoint's number, for ConfigPropsEndpoint's reason: a bound is what makes the walk total
     * against a structure that refers to itself, and eight levels is deeper than any context an application
     * hangs off a throw site.
     */
    private const int MAX_DEPTH = 8;

    /**
     * THE DISCLOSURE GATE IS THE PROBLEM DOCUMENT'S OWN. For one release this path shared the HTML page's
     * `trace` (which follows `app.debug`), and that was the wrong gate for a machine surface: every local
     * and compose environment sets APP_DEBUG, so a console fed by problem+json rendered a QueryException's
     * DSN, tenant id and full statement in a red banner while the HTML page beside it withheld everything.
     * `ErrorPageSettings::$disclose` (`firefly.web.problem.disclose`) is read instead, defaults to false and
     * inherits from nothing. The settings object is optional so a JSON-only deployment that never bound one
     * still renders — and when it is absent the default is the SAFE one.
     *
     * THE LOGGER IS THE DEGRADED DOCUMENT'S WITNESS, and it has no configuration key of its own on purpose.
     * It is not a feature an application turns on: it is the record of a failure INSIDE the error handler,
     * and a key whose off position means "lose the only evidence" is a key nobody should be offered. It is
     * optional for the same reason the settings object is — a JSON-only deployment, or one of the dozens of
     * `new ProblemDetailsRenderer` in this repository's tests, binds no logger and must still render — and
     * WebServiceProvider hands over the application's when there is one. Nothing else in this class reads
     * it; see report(), which is also where a logger that throws is dealt with.
     */
    public function __construct(
        private readonly ?ErrorPageSettings $settings = null,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function render(Throwable $e, Request $request): Response
    {
        // An absent settings object means the SAFE answer, not the open one — see the constructor.
        $disclose = $this->settings instanceof ErrorPageSettings && $this->settings->disclose;

        $correlationId = CorrelationIdFilter::of($request);
        $reference = TraceContext::referenceFor($request);
        $exception = ProblemMapper::toFireflyException($e, $disclose, $reference);

        // The correlation id keeps its own member beside the trace id. They are usually different values
        // with different jobs — one finds the trace, one matches the caller's own request log — and a
        // document that published only the first would make the second unrecoverable from the response.
        // It is passed THROUGH ErrorResponse rather than written onto the array afterwards: the DTO's
        // member list is what the published OpenAPI component is generated and guarded from, so a member
        // appended here would be one no generated client decodes.
        $problem = ErrorResponse::fromException(
            $exception,
            // RFC 9457 §3.1.5: `instance` is a URI REFERENCE, and a relative one resolves against the
            // document's base URI — so the bare `api/orders/42` this used to pass, served from
            // /api/orders/42, identified /api/api/orders/42. The rule is ProblemMapper's because the HTML
            // page beside this one builds the same reference, and two spellings of it would eventually
            // disagree about the same request.
            instance: ProblemMapper::instanceFor($request),
            traceId: $reference,
            timestamp: (new DateTimeImmutable)->format(DateTimeInterface::ATOM),
            correlationId: $correlationId,
        );

        // RFC 9457 §3.1.1, carried on the DTO rather than written onto toArray()'s output: `type` is a
        // DECLARED member of ErrorResponse and of the published ProblemSchema, and the class comment at
        // ErrorResponse.php:24 is explicit that a member appended downstream is one every generated client
        // drops. Re-declaring the DTO with one field changed is the honest way to set it on a readonly
        // value object, and it keeps toArray()'s member ORDER the single source of truth.
        $problem = new ErrorResponse(
            status: $problem->status,
            title: $problem->title,
            code: $problem->code,
            category: $problem->category,
            severity: $problem->severity,
            detail: $problem->detail,
            type: ProblemType::of($problem->code, $this->settings instanceof ErrorPageSettings ? $this->settings->typeUri : ProblemType::BLANK),
            instance: $problem->instance,
            traceId: $problem->traceId,
            errors: $problem->errors,
            timestamp: $problem->timestamp,
            extensions: $problem->extensions,
            correlationId: $problem->correlationId,
        );

        $payload = $problem->toArray();

        $headers = [
            'Content-Type' => 'application/problem+json',
            CorrelationIdFilter::HEADER => $correlationId,
        ];

        $traceHeader = TraceContext::header();
        $traceId = TraceContext::traceId($request);
        if ($traceHeader !== '' && $traceId !== null) {
            $headers[$traceHeader] = $traceId;
        }

        if ($e instanceof HttpExceptionInterface) {
            foreach ($e->getHeaders() as $name => $value) {
                $headers[$name] = $value;
            }
        }

        if ($exception->httpStatus() === 503) {
            $headers['Retry-After'] = (string) self::RETRY_AFTER_SECONDS;
        }

        return new Response(
            $this->encode($payload, $reference),
            $exception->httpStatus(),
            $headers,
        );
    }

    /**
     * The payload as JSON, whatever the payload turns out to contain.
     *
     * THE RENDERER RUNS WHILE THE APPLICATION IS ALREADY FAILING, and json_encode had a live failure mode on
     * exactly that path: JSON_THROW_ON_ERROR turns a single byte that is not valid UTF-8 — anywhere in the
     * document — into a JsonException thrown OUT of this method, so the error handler fails while handling
     * the error and the caller receives no document at all. Those bytes are not exotic: a driver message
     * quoting a latin-1 column value, a request header echoed into an extension member at the throw site, a
     * file name off a filesystem that is not UTF-8.
     *
     * JSON_INVALID_UTF8_SUBSTITUTE answers that case properly — the offending bytes become U+FFFD and the
     * document is still the document. The try/catch is the belt to that pair of braces, and it catches
     * THROWABLE RATHER THAN JsonException BECAUSE JSON_THROW_ON_ERROR DOES NOT COVER THE WHOLE FAILURE SET.
     * Two different kinds of thing end up here:
     *
     *   - What json_encode refuses AND reports — recursion, a resource, an INF or NAN an application put in
     *     an extension member. This is the arm JSON_THROW_ON_ERROR turns into a JsonException.
     *   - What json_encode never sees as an error at all. Encoding an object CALLS the application's own
     *     code — JsonSerializable::jsonSerialize(), an Eloquent accessor, a decrypting cast — and whatever
     *     that code raises propagates straight out of json_encode with no error state set and no
     *     JsonException anywhere in it. Catching JsonException left this arm open.
     *
     * The second arm is ordinary, not exotic, and it is worst exactly here: extension members are `mixed`
     * and are chosen at the throw site by withExtensions(), FieldError::$rejectedValue is `mixed` too, and
     * an object whose accessor reads the database is most likely to throw when the database is the thing
     * that already failed — which is to say, while this renderer is describing that failure. Neither arm is
     * a reason to answer a caller with nothing.
     *
     * AND NEITHER ARM IS A REASON TO SAY NOTHING EITHER, which is what the first spelling of this method
     * did. `catch (Throwable) { $json = false; }` swallowed an arbitrary application exception whole: the
     * RuntimeException an Eloquent accessor raised under preventLazyLoading went into that pair of braces
     * and out of the process, and the degraded document that came back was byte-indistinguishable from a
     * healthy one. Both halves of that are fixed below — the catch BINDS the throwable and report() hands
     * it to the logger, and minimal() names every member it could not carry instead of deleting it — so
     * the one arm of this subsystem that can still fail is the one arm nobody could previously observe.
     *
     * @param  array<string, mixed>  $payload
     * @param  ?string  $reference  the document's own `traceId`, so the log line and the body a caller is
     *                              holding can be joined up — the whole point of publishing one
     */
    private function encode(array $payload, ?string $reference): string
    {
        try {
            // JSON_THROW_ON_ERROR is what makes the catch the WHOLE fallback: with it set json_encode never
            // answers false, it raises, so there is no second failure mode for this method to miss.
            return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        } catch (Throwable $cause) {
            $this->report($cause, $reference);

            return self::minimal($payload);
        }
    }

    /**
     * Says that a problem document was degraded, and why, to whoever is listening.
     *
     * IT IS WRAPPED IN ITS OWN try/catch, and that is not belt-and-braces theatre. This runs inside the
     * error handler, with an application already failing, and the logger is a live collaborator: a handler
     * writing to a full disk, a channel whose remote endpoint is the thing that went down, a Monolog
     * processor reading the same broken context. A throw from HERE would escape render() and produce
     * exactly the blank 500 the encoder was made total to prevent — the bug back again, one layer up and
     * wearing the fix's own clothes. So a logger that fails costs the log line and nothing else.
     *
     * The message says what happened to the DOCUMENT, because that is what an operator is holding when
     * they come looking; `exception` carries the cause under the key PSR-3 reserves for it, which is the
     * key Laravel's formatter already knows to expand into a class, a message and a trace.
     */
    private function report(Throwable $cause, ?string $reference): void
    {
        if (! $this->logger instanceof LoggerInterface) {
            return;
        }

        $context = ['exception' => $cause, 'reference' => $reference ?? ''];

        try {
            $this->logger->error(
                'The problem document could not be encoded and was degraded: a member it was given cannot be JSON.',
                $context,
            );
        } catch (Throwable) {
            // See the docblock: the renderer still owes the caller a document, and it is about to return one.
        }
    }

    /**
     * A document that CANNOT fail to encode: the standard members ErrorResponse declares, each rebuilt from
     * a value whose type is checked here rather than trusted, with every string passing through the same
     * substitution.
     *
     * WHAT IS DROPPED IS WHAT COULD BE THE CAUSE, AND NOTHING ELSE — down to the VALUE, not the member that
     * holds it and not the array that holds the member. Only a NON-string reaches this branch —
     * JSON_INVALID_UTF8_SUBSTITUTE has already answered every bad byte — so what brought us here is an
     * extension member an application chose at the throw site, a FieldError::$rejectedValue (`mixed`, so
     * literally whatever the client sent), a structure too deep or too circular to walk, or an object whose
     * own accessor threw mid-encode. The open namespace is therefore RENDERED, not deleted: encodable()
     * replaces a value json_encode could refuse with the name of its type and keeps every value that was
     * never in question, so `allowed` — which ProblemMapper itself publishes as an extension on a 405 —
     * still lists the verbs, and a `balance` that cannot be carried says `App\Models\Balance` instead of
     * vanishing. Deleting the namespace wholesale is what made a degraded document unreadable AND
     * indistinguishable; ConfigPropsEndpoint::value() has rendered the unencodable as its type since it
     * shipped, for the same stated reason, and one framework should answer one question one way.
     * `errors` keeps its four declared-string members and drops the one `mixed` one — see fieldErrors(),
     * which is where the difference between an operator's context and a sentence shown to a person is
     * argued. The standard members STAY, because ErrorResponse declares every one of them `?string`
     * (`int`, for the status) and array_diff_key() keeps a same-named extension out of the document, so
     * not one of them can be why we are here and dropping them buys nothing:
     *
     *   - `category` and `severity` pinned to Internal/Error for every exception publishes a document that
     *     contradicts its own status and code — a 409 whose category reads `internal`, a 422 a generated
     *     client branching on `category == 'validation'` renders down the wrong arm. They are re-derived
     *     through tryFrom() rather than copied so the published schema's enum constraint holds whatever the
     *     payload turns out to say. Keeping `category: validation` is only half the promise, and
     *     fieldErrors() is the other half: the arm the client renders has to have something in it.
     *   - `detail`, `traceId` and `correlationId` dropped publishes exactly the document whose body a person
     *     cannot correlate, against this class's promise that quoting the reference is possible from the body
     *     alone — and replaces an authored sub-500 sentence that ProblemMapper's disclosure gate had already
     *     cleared for publication with an opaque internal one that withholds nothing it had not already let
     *     through.
     *
     * The literal at the bottom is unreachable and is written anyway: a renderer on the error path does not
     * get to assume.
     *
     * @param  array<string, mixed>  $payload
     */
    private static function minimal(array $payload): string
    {
        $status = is_int($payload['status'] ?? null) ? $payload['status'] : 500;
        $category = ErrorCategory::tryFrom(self::member($payload, 'category', '')) ?? ErrorCategory::Internal;
        $severity = ErrorSeverity::tryFrom(self::member($payload, 'severity', '')) ?? ErrorSeverity::Error;

        $document = [
            'status' => $status,
            'title' => self::member($payload, 'title', ErrorResponse::titleFor($status)),
            'code' => self::member($payload, 'code', 'INTERNAL_ERROR'),
            'category' => $category->value,
            'severity' => $severity->value,
            'detail' => self::member($payload, 'detail', ProblemMapper::OPAQUE),
        ];

        // The rest are optional in the DOCUMENT as well as on the DTO — toArray() writes each one only when
        // it is non-null — so an absent member stays absent here rather than becoming an invented empty
        // string. The order is STANDARD_MEMBERS', so the degraded document reads like the full one.
        foreach (['type', 'instance', 'traceId', 'correlationId', 'timestamp'] as $member) {
            $value = $payload[$member] ?? null;

            if (is_string($value)) {
                $document[$member] = $value;
            }
        }

        // LAST of the standard members, where STANDARD_MEMBERS has it, so the degraded document still reads
        // like the full one.
        $errors = self::fieldErrors($payload);

        if ($errors !== []) {
            $document['errors'] = $errors;
        }

        // AND THE OPEN NAMESPACE AFTER THEM, which is where ErrorResponse::toArray() puts it: the standard
        // members lead every problem document this framework publishes and the extensions follow, so the
        // degraded one diffs against the full one member for member rather than looking like a third shape.
        foreach (self::extensions($payload) as $name => $value) {
            $document[$name] = $value;
        }

        $json = json_encode($document, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        return is_string($json)
            ? $json
            : '{"status":500,"title":"Internal Server Error","code":"INTERNAL_ERROR","category":"internal","severity":"error"}';
    }

    /**
     * The RFC 9457 open namespace of the payload — every member that is not one ErrorResponse defines —
     * with each value rendered as something json_encode cannot refuse.
     *
     * THE NAMES ARE THE POINT. An application puts context on an exception at the throw site precisely
     * because that context is what makes the failure legible — the tenant, the order id, the upstream it
     * called — and for one release this method did not exist and the whole namespace was deleted the moment
     * ANY member of the document failed to encode. One unreadable `balance` took `tenant` and `orderId`
     * with it, and took the framework's OWN extension with it too: ProblemMapper publishes a 405's verb
     * list as `allowed`, a plain list of strings that can never be why an encode failed. Keeping the
     * members and rendering the values is strictly more truthful than dropping them, and strictly less
     * disclosure than the healthy document already published — an object that would have been serialised
     * with every property it owns is named by its class and nothing else.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function extensions(array $payload): array
    {
        $members = [];

        foreach (array_diff_key($payload, array_flip(ErrorResponse::STANDARD_MEMBERS)) as $name => $value) {
            $members[$name] = self::encodable($value);
        }

        return $members;
    }

    /**
     * One value of the open namespace, rendered as something json_encode cannot refuse and — the half that
     * matters here — cannot have to ASK THE APPLICATION about.
     *
     * TOTALITY IS THE WHOLE REQUIREMENT, and it is why this walks types rather than trying values. Scalars
     * and null are already JSON. Arrays are descended, to a bound, because a circular one is reachable
     * through a reference and an unbounded walk would exchange a failed encode for a failed stack. A
     * non-finite float is NAMED rather than typed: get_debug_type() would say `float`, which is the single
     * least interesting true thing about an INF, and an INF in an extension member is not hypothetical —
     * json_decode('{"ratio": 1e999}') is exactly that, so any caller can post one. Enums answer from their
     * own case, which is a property read and not a method call. EVERYTHING ELSE — an object, a resource, a
     * closure — becomes get_debug_type(), and get_debug_type() is the only answer available: asking an
     * object for its value is calling the application's code, and calling the application's code is what
     * threw on the way in here. That is ConfigPropsEndpoint::value()'s reasoning and very nearly its
     * shape, minus the one branch it has that this cannot have — it descends into an object's properties,
     * and it may, because the objects it walks are config DTOs the framework built itself.
     */
    private static function encodable(mixed $value, int $depth = 0): mixed
    {
        if ($value === null || is_bool($value) || is_int($value) || is_string($value)) {
            return $value;
        }

        if (is_float($value)) {
            if (is_finite($value)) {
                return $value;
            }

            return is_nan($value) ? 'NAN' : ($value > 0 ? 'INF' : '-INF');
        }

        if (is_array($value)) {
            if ($depth > self::MAX_DEPTH) {
                return 'array';
            }

            $mapped = [];

            foreach ($value as $key => $item) {
                $mapped[$key] = self::encodable($item, $depth + 1);
            }

            return $mapped;
        }

        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof UnitEnum) {
            return $value->name;
        }

        return get_debug_type($value);
    }

    /**
     * The field errors of the payload, rebuilt from the members FieldError DECLARES to be strings.
     *
     * DROPPING `errors` WHOLESALE DROPPED FAR MORE THAN THE CAUSE. FieldError declares `$field` and
     * `$message` as `string` and `$code`/`$constraint` as `?string`, so not one of those four can ever be
     * what json_encode refused; `$rejectedValue` is the only `mixed` member, and it is the CLIENT'S own
     * value — the validators fill it with `Arr::get($data, $field)` straight off the decoded request body,
     * which is how a posted `{"ratio": 1e999}` arrives here as INF. So one caller posting one number used
     * to get back a 422 that said `category: validation` and carried no `errors` member at all, taking
     * every OTHER field's sentence down with it — a document that invites a generated client down the
     * field-error arm and then hands that arm nothing. The rejected value goes. The field, the sentence,
     * the code and the constraint stay.
     *
     * AND THE REJECTED VALUE GOES RATHER THAN BEING NAMED, which is the opposite of what happens to an
     * extension member one line above, on purpose. `rejectedValue` is an ECHO: its whole contract is "this
     * is the value you sent", and a client renders it back to the person who sent it. Writing `"float"`
     * there would not be a degraded truth, it would be a false sentence shown to a human — nobody posted
     * the word float. An extension member has no such contract: it is context an application chose for an
     * OPERATOR, and naming its type is the most useful thing that can honestly be said about a value
     * nobody can read. An absent `rejectedValue` is already a shape every client handles, because
     * FieldError declares it nullable and omits it when there is none.
     *
     * Every member is type-checked rather than trusted, for minimal()'s reason: we are on this path
     * precisely because the payload was not the shape it claims to be. An entry that is not an array, or
     * one that keeps no member at all, contributes nothing instead of contributing an empty object — and
     * checking the type is also what keeps this total, since reading only strings out of the payload
     * cannot re-enter the application code that may have thrown on the way in.
     *
     * @param  array<string, mixed>  $payload
     * @return list<array<string, string>>
     */
    private static function fieldErrors(array $payload): array
    {
        $entries = $payload['errors'] ?? null;

        if (! is_array($entries)) {
            return [];
        }

        $errors = [];

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $kept = [];

            // FieldError::toArray()'s order, minus the one member that could be why we are here.
            foreach (['field', 'message', 'code', 'constraint'] as $member) {
                $value = $entry[$member] ?? null;

                if (is_string($value)) {
                    $kept[$member] = $value;
                }
            }

            if ($kept !== []) {
                $errors[] = $kept;
            }
        }

        return $errors;
    }

    /**
     * One standard member of the payload, when it is the string ErrorResponse declares it to be.
     *
     * The type check is not ceremony: minimal() runs because something in this payload was not what the
     * document's shape says it is, and a member read on trust here would take the fallback down with it.
     *
     * @param  array<string, mixed>  $payload
     */
    private static function member(array $payload, string $name, string $fallback): string
    {
        $value = $payload[$name] ?? null;

        return is_string($value) ? $value : $fallback;
    }
}
