<?php

declare(strict_types=1);

namespace Indexr\Otel\Core;

use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use Throwable;

/** W3C trace-context carrier helpers for HTTP headers and queued message payloads. */
final class TraceContext
{
    private const MAX_TRACESTATE_LENGTH = 512;

    /** Returns a parent context for a valid `traceparent`, or null so the caller starts a new trace. */
    public static function extract(mixed $traceparent, mixed $tracestate = null): ?ContextInterface
    {
        if (!is_string($traceparent) || $traceparent === '' || strlen($traceparent) > 64) {
            return null;
        }
        $carrier = ['traceparent' => $traceparent];
        if (is_string($tracestate) && $tracestate !== '' && strlen($tracestate) <= self::MAX_TRACESTATE_LENGTH) {
            $carrier['tracestate'] = $tracestate;
        }
        try {
            $context = TraceContextPropagator::getInstance()->extract($carrier, null, Context::getRoot());
            return Span::fromContext($context)->getContext()->isValid() ? $context : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array{traceparent?: string, tracestate?: string} The active span's context, empty when none is valid. */
    public static function current(): array
    {
        try {
            if (!Span::getCurrent()->getContext()->isValid()) {
                return [];
            }
            $carrier = [];
            TraceContextPropagator::getInstance()->inject($carrier);
            return array_filter(
                array_intersect_key($carrier, ['traceparent' => true, 'tracestate' => true]),
                static fn (mixed $value): bool => is_string($value) && $value !== '',
            );
        } catch (Throwable) {
            return [];
        }
    }
}
