<?php

declare(strict_types=1);

namespace Indexr\Otel\Core;

use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanContextInterface;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\ScopeInterface;
use Throwable;

/** Owns an activated span until the instrumented operation has completed. */
final class ActiveSpan
{
    /** @var array<int, self> Spans that are active and not yet finished, by object ID. */
    private static array $open = [];
    private static bool $shutdownRegistered = false;

    private bool $finished = false;

    public function __construct(private readonly SpanInterface $span, private readonly ScopeInterface $scope)
    {
        // Some paths never reach the code that finishes a span (for example a command that rebuilds the kernel's
        // container mid-run, so its terminate event reaches nothing). A leaked scope makes the OpenTelemetry SDK's
        // debug scope raise a notice at shutdown, which a framework in debug mode turns into a fatal error, so any
        // span still open when the process ends is finished then.
        self::$open[spl_object_id($this)] = $this;
        if (!self::$shutdownRegistered) {
            self::$shutdownRegistered = true;
            register_shutdown_function([self::class, 'finishOpenSpans']);
        }
    }

    /** Finishes every span that is still open, most recently opened first so scopes detach in order. */
    public static function finishOpenSpans(): void
    {
        foreach (array_reverse(self::$open, true) as $span) {
            try {
                $span->finish();
            } catch (Throwable) {
            }
        }
    }

    /** Detaches the scope and ends the span, optionally at an earlier recorded time. Safe to call twice. */
    public function finish(?Throwable $failure = null, ?int $endEpochNanos = null): void
    {
        if ($this->finished) {
            return;
        }
        $this->finished = true;
        unset(self::$open[spl_object_id($this)]);
        if ($failure !== null) {
            $this->markError($failure::class);
        }
        try {
            $this->scope->detach();
        } catch (Throwable) {
        }
        try {
            $this->span->end($endEpochNanos);
        } catch (Throwable) {
        }
    }

    public function markError(?string $errorType = null): void
    {
        try {
            if ($this->span->isRecording()) {
                if ($errorType !== null && $errorType !== '') {
                    $this->span->setAttribute('error.type', $errorType);
                }
                $this->span->setStatus(StatusCode::STATUS_ERROR);
            }
        } catch (Throwable) {
        }
    }

    /** @param array<string, mixed> $attributes */
    public function enrich(array $attributes): void
    {
        try {
            (new SpanEnricher())->enrich($this->span, $attributes);
        } catch (Throwable) {
        }
    }

    public function context(): SpanContextInterface
    {
        return $this->span->getContext();
    }

    public function isFinished(): bool
    {
        return $this->finished;
    }
}
