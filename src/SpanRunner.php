<?php

declare(strict_types=1);

namespace Indexr\Otel\Core;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\Context\ContextInterface;
use Throwable;

/** Starts a safe application span and owns its active context scope. */
final class SpanRunner
{
    /**
     * @param non-empty-string $name
     * @param array<string, mixed> $attributes
     * @param 0|1|2|3|4 $kind
     * @param ContextInterface|null $parent Explicit parent, such as a propagated remote context; the active context otherwise.
     * @param int|null $startEpochNanos Back-dated start, such as the web server's request start time.
     */
    public function begin(
        string $name,
        array $attributes,
        int $kind = SpanKind::KIND_INTERNAL,
        ?ContextInterface $parent = null,
        ?int $startEpochNanos = null,
    ): ?ActiveSpan {
        try {
            $builder = Globals::tracerProvider()->getTracer(SpanSymbolIdProcessor::OWN_TRACER)->spanBuilder($name)
                ->setSpanKind($kind);
            if ($parent !== null) {
                $builder->setParent($parent);
            }
            if ($startEpochNanos !== null && $startEpochNanos > 0) {
                $builder->setStartTimestamp($startEpochNanos);
            }
            $span = $builder->startSpan();
            if (!$span->isRecording()) {
                $span->end();
                return null;
            }
            (new SpanEnricher())->enrich($span, $attributes);
            return new ActiveSpan($span, $span->activate());
        } catch (Throwable) {
            return null;
        }
    }
}
