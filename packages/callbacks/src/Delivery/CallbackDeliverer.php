<?php

declare(strict_types=1);

namespace Firefly\Callbacks\Delivery;

use Closure;
use Firefly\Callbacks\CallbacksSettings;
use Firefly\Callbacks\Execution\CallbackExecution;
use Firefly\Callbacks\Execution\CallbackStatus;
use Firefly\Callbacks\Subscription\CallbackSubscription;
use Firefly\Kernel\Exception\Infrastructure\CircuitBreakerOpenException;
use Throwable;

/**
 * Sends one execution to one subscription and answers how it ended — it never throws.
 *
 *   * An inactive subscription is refused before anything is sent: failed, "subscription [id] is inactive". This is
 *     the one delivery path, so dispatch and replay alike fail closed; only a caller that explicitly includes
 *     inactive subscriptions (`firefly:callbacks:replay --include-inactive`) gets past it.
 *   * The destination is the subscription's CURRENT URL, never the one stored on the execution: an endpoint changed
 *     since the execution was logged is delivered to (and checked) at its new address, and the returned execution
 *     carries that URL, so the log records where it was actually sent.
 *   * The URL must be http(s) — https unless firefly.callbacks.http.require-https is false — and, when
 *     firefly.callbacks.http.allowed-hosts names hosts, on one of them or a subdomain (PyFly's authorized domains:
 *     the guard against a subscription aimed at an internal address).
 *   * Each attempt is signed afresh and sent inside the endpoint's circuit breaker. 2xx: delivered. 408, 429, 5xx
 *     and transport errors: retried after initial-delay × multiplier^(n−1), capped at max-delay. Any other status:
 *     failed at once, it is the receiver's final word. An OPEN breaker stops the attempts: failed, "circuit open".
 *   * Attempts accumulate across replays, so the log shows every try an execution ever had.
 */
final class CallbackDeliverer
{
    /** @var Closure(float): void */
    private readonly Closure $sleep;

    /** @var Closure(): int */
    private readonly Closure $clock;

    /**
     * @param  (Closure(float): void)|null  $sleep
     * @param  (Closure(): int)|null  $clock  unix seconds
     */
    public function __construct(
        private readonly CallbacksSettings $settings,
        private readonly CallbackSender $sender,
        private readonly CallbackSigner $signer,
        private readonly ?EndpointCircuitBreakers $breakers = null,
        ?Closure $sleep = null,
        ?Closure $clock = null,
    ) {
        $this->sleep = $sleep ?? static function (float $seconds): void {
            if ($seconds > 0.0) {
                usleep((int) round($seconds * 1_000_000));
            }
        };
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function deliver(CallbackSubscription $subscription, CallbackExecution $execution, bool $includeInactive = false): CallbackExecution
    {
        if (! $subscription->active && ! $includeInactive) {
            return $execution->withOutcome(CallbackStatus::Failed, $execution->attempts, null, "subscription [{$subscription->id}] is inactive", ($this->clock)());
        }

        if ($execution->url !== $subscription->url) {
            $execution = $execution->withUrl($subscription->url);
        }

        $refusal = $this->refusal($execution->url);
        if ($refusal !== null) {
            return $execution->withOutcome(CallbackStatus::Failed, $execution->attempts, null, $refusal, ($this->clock)());
        }

        $maxAttempts = max(1, $subscription->maxAttempts ?? $this->settings->maxAttempts);
        $attempts = $execution->attempts;
        $status = null;
        $error = null;

        for ($try = 1; $try <= $maxAttempts; $try++) {
            $attempts++;

            try {
                $status = $this->attempt($subscription, $execution);
                if ($status >= 200 && $status < 300) {
                    return $execution->withOutcome(CallbackStatus::Delivered, $attempts, $status, null, ($this->clock)());
                }

                return $execution->withOutcome(CallbackStatus::Failed, $attempts, $status, "HTTP {$status} is not retryable", ($this->clock)());
            } catch (CircuitBreakerOpenException) {
                return $execution->withOutcome(CallbackStatus::Failed, $attempts - 1, $status, 'circuit open for '.$subscription->endpoint(), ($this->clock)());
            } catch (RetryableDeliveryFailure $e) {
                $status = $e->status;
                $error = $e->getMessage();
            } catch (Throwable $e) {
                $error = $e::class.': '.$e->getMessage();
            }

            if ($try < $maxAttempts) {
                ($this->sleep)($this->delayAfter($try));
            }
        }

        return $execution->withOutcome(CallbackStatus::Failed, $attempts, $status, $error, ($this->clock)());
    }

    public function delayAfter(int $failures): float
    {
        return max(0.0, min($this->settings->maxDelay, $this->settings->initialDelay * ($this->settings->multiplier ** max(0, $failures - 1))));
    }

    private function attempt(CallbackSubscription $subscription, CallbackExecution $execution): int
    {
        $headers = array_merge(
            $subscription->headers,
            [
                'Content-Type' => 'application/json',
                'User-Agent' => 'LaraFly-Callbacks',
                'X-Firefly-Event' => $execution->eventType,
                'X-Firefly-Event-Id' => $execution->eventId,
                'X-Firefly-Delivery' => $execution->id,
            ],
            $this->signer->sign($execution->body, $subscription->secret, ($this->clock)()),
        );

        $send = function () use ($execution, $headers): int {
            $status = $this->sender->send($execution->url, $execution->body, $headers, $this->settings->timeout);
            if ($status === 408 || $status === 429 || $status >= 500) {
                throw new RetryableDeliveryFailure($status);
            }

            return $status;
        };

        return $this->breakers === null ? $send() : $this->breakers->for($subscription->endpoint())->call($send);
    }

    private function refusal(string $url): ?string
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return "[{$url}] is not an http(s) URL";
        }

        if ($scheme === 'http' && $this->settings->requireHttps) {
            return "[{$url}] is not https and firefly.callbacks.http.require-https is on";
        }

        if ($this->settings->allowedHosts !== []) {
            foreach ($this->settings->allowedHosts as $allowed) {
                if ($host === $allowed || str_ends_with($host, '.'.$allowed)) {
                    return null;
                }
            }

            return "host [{$host}] is not in firefly.callbacks.http.allowed-hosts";
        }

        return null;
    }
}
