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
    private bool $finished = false;

    public function __construct(private readonly SpanInterface $span, private readonly ScopeInterface $scope)
    {
    }

    /** Detaches the scope and ends the span, optionally at an earlier recorded time. Safe to call twice. */
    public function finish(?Throwable $failure = null, ?int $endEpochNanos = null): void
    {
        if ($this->finished) {
            return;
        }
        $this->finished = true;
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
