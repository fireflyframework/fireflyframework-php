<?php

declare(strict_types=1);

namespace Firefly\Web\Error;

use Firefly\Kernel\Error\ErrorResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Everything the HTML error page shows, assembled once from the exception, the request and the settings.
 *
 * SEPARATE FROM THE PAGE ON PURPOSE. Deciding what may be disclosed and building markup are different jobs
 * with different failure modes, and keeping them apart means the disclosure rule is stated once, in one
 * place, and is testable without parsing HTML. It also makes the rule enforceable rather than decorative:
 * when `trace` is off, this class never reads a source file, never walks the trace and never copies the
 * exception message — so a template mistake cannot leak what was never gathered.
 *
 * THE FIELDS MATCH THE PROBLEM DOCUMENT. `code`, `category` and `severity` come from the same
 * ErrorResponse::fromException() the JSON renderer uses, so the page a browser sees and the payload a client
 * sees describe the same error with the same vocabulary. A support ticket quoting the code off the page
 * finds the same code in the log.
 */
final readonly class ErrorReport
{
    /**
     * @param  list<ErrorFrame>  $frames
     * @param  list<array{class: string, message: string, location: string}>  $previous
     */
    private function __construct(
        public int $status,
        public string $reason,
        public string $code,
        public string $category,
        public string $severity,
        public string $method,
        public string $path,
        public string $timestamp,
        public bool $detailed,
        public string $exceptionClass = '',
        public string $message = '',
        public string $location = '',
        public array $frames = [],
        public array $previous = [],
    ) {}

    public static function of(Throwable $e, Request $request, ErrorPageSettings $settings, string $basePath, int $status, string $reason, string $timestamp): self
    {
        $payload = ErrorResponse::fromException(ProblemMapper::toFireflyException($e), instance: $request->path(), timestamp: $timestamp)->toArray();

        $public = new self(
            status: $status,
            reason: $reason,
            code: is_string($payload['code'] ?? null) ? $payload['code'] : 'INTERNAL_ERROR',
            category: is_string($payload['category'] ?? null) ? $payload['category'] : '',
            severity: is_string($payload['severity'] ?? null) ? $payload['severity'] : '',
            method: $request->getMethod(),
            path: '/'.ltrim($request->path(), '/'),
            timestamp: $timestamp,
            detailed: false,
        );

        if (! $settings->trace) {
            return $public;
        }

        return new self(
            status: $public->status,
            reason: $public->reason,
            code: $public->code,
            category: $public->category,
            severity: $public->severity,
            method: $public->method,
            path: $public->path,
            timestamp: $public->timestamp,
            detailed: true,
            exceptionClass: $e::class,
            message: $e->getMessage(),
            location: self::shorten($e->getFile(), $basePath).':'.$e->getLine(),
            frames: self::frames($e, $basePath, $settings->excerptLines),
            previous: self::previous($e, $basePath),
        );
    }

    /**
     * The throw site first, then the call stack — which is the order a reader wants and the opposite of the
     * order `getTrace()` returns it in relative to `getFile()`. PHP's trace starts at the CALLER of the
     * throwing frame, so the throwing line itself appears nowhere in it and has to be prepended.
     *
     * @return list<ErrorFrame>
     */
    private static function frames(Throwable $e, string $basePath, int $excerptLines): array
    {
        $frames = [self::frame($e->getFile(), $e->getLine(), 'throw', $basePath, $excerptLines)];

        foreach ($e->getTrace() as $entry) {
            $file = is_string($entry['file'] ?? null) ? $entry['file'] : '';
            $line = is_int($entry['line'] ?? null) ? $entry['line'] : null;

            $class = $entry['class'] ?? '';
            $type = $entry['type'] ?? '';
            $function = $entry['function'];

            $frames[] = self::frame($file, $line, $class.$type.$function.'()', $basePath, $excerptLines);
        }

        return $frames;
    }

    private static function frame(string $file, ?int $line, string $call, string $basePath, int $excerptLines): ErrorFrame
    {
        $vendor = $file === '' || str_contains($file, '/vendor/') || str_contains($file, '\\vendor\\');

        return new ErrorFrame(
            file: $file,
            shortFile: $file === '' ? '[internal function]' : self::shorten($file, $basePath),
            line: $line,
            call: $call,
            vendor: $vendor,
            excerpt: $vendor ? [] : self::excerpt($file, $line, $excerptLines),
        );
    }

    /**
     * The lines around $line, as line number => text.
     *
     * Guarded at every step because this runs while the application is ALREADY failing: the file may have
     * been deleted since the trace was captured, may be unreadable, or may be an eval()'d fragment with no
     * path at all. An error page that throws while explaining a throw is the worst possible outcome, so
     * every branch here answers with an empty excerpt rather than an exception.
     *
     * @return array<int, string>
     */
    private static function excerpt(string $file, ?int $line, int $radius): array
    {
        if ($line === null || $radius === 0 || $file === '' || ! is_file($file) || ! is_readable($file)) {
            return [];
        }

        // A generated proxy or a minified vendor bundle can be one enormous line; reading it whole to show
        // seven lines around a fault is not a trade worth making on a page that renders under duress.
        if ((filesize($file) ?: 0) > 2 * 1024 * 1024) {
            return [];
        }

        $lines = @file($file, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return [];
        }

        $from = max(1, $line - intdiv($radius, 2));
        $to = min(count($lines), $from + $radius - 1);

        $excerpt = [];
        for ($n = $from; $n <= $to; $n++) {
            $excerpt[$n] = $lines[$n - 1] ?? '';
        }

        return $excerpt;
    }

    /**
     * The `previous` chain, which is where the real cause usually is: firefly/web wraps a binding failure in
     * an InvalidRequestException, the container wraps a constructor throw, and the message on the outermost
     * exception is the least specific one in the chain.
     *
     * @return list<array{class: string, message: string, location: string}>
     */
    private static function previous(Throwable $e, string $basePath): array
    {
        $chain = [];
        $seen = 0;

        while (($e = $e->getPrevious()) !== null && $seen < 8) {
            $seen++;
            $chain[] = [
                'class' => $e::class,
                'message' => $e->getMessage(),
                'location' => self::shorten($e->getFile(), $basePath).':'.$e->getLine(),
            ];
        }

        return $chain;
    }

    private static function shorten(string $file, string $basePath): string
    {
        if ($basePath !== '' && str_starts_with($file, $basePath)) {
            return ltrim(substr($file, strlen($basePath)), '/\\');
        }

        return $file;
    }
}
