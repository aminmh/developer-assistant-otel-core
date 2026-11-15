<?php

declare(strict_types=1);

namespace Indexr\Otel\Core;

use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Trace\ReadableSpanInterface;
use OpenTelemetry\SDK\Trace\ReadWriteSpanInterface;
use OpenTelemetry\SDK\Trace\SpanProcessorInterface;

/** Sampling-aware processor for symbol and caller IDs on recorded spans. */
final class SpanSymbolIdProcessor implements SpanProcessorInterface
{
    /** Spans from this tracer are attributed by their own runners; a second backtrace would only repeat it. */
    public const OWN_TRACER = 'indexr/indexer-otel-core';

    private readonly string $projectRoot;

    /** @param list<string> $applicationNamespaces */
    public function __construct(private readonly array $applicationNamespaces, ?string $projectRoot = null)
    {
        $this->projectRoot = $projectRoot !== null && $projectRoot !== '' ? $projectRoot : (getcwd() ?: '.');
    }

    public function onStart(ReadWriteSpanInterface $span, ContextInterface $parentContext): void
    {
        try {
            if (!$span->isRecording()) {
                return;
            }
            $redactor = new Redactor();
            $attributes = $span->toSpanData()->getAttributes()->toArray();
            $safe = $redactor->attributes($attributes);
            foreach (array_diff(array_keys($attributes), array_keys($safe)) as $sensitiveKey) {
                if (is_string($sensitiveKey) && $sensitiveKey !== '') {
                    $span->setAttribute($sensitiveKey, null);
                }
            }
            if (is_string($safe['db.query.text'] ?? null)) {
                $query = (new SqlAnalyzer())->analyze($safe['db.query.text']);
                $span->setAttribute('db.query.text', $query->normalized);
                if ($query->tableIds !== []) {
                    $span->setAttribute('indexer.table_ids', $query->tableIds);
                }
            }
            foreach ($safe as $key => $value) {
                if ($key !== '') {
                    $span->setAttribute($key, $value);
                }
            }
            if ($span->getInstrumentationScope()->getName() === self::OWN_TRACER) {
                return;
            }
            if ($span->getAttribute('indexer.symbol_id') !== null && $span->getAttribute('indexer.caller.symbol_id') !== null) {
                return;
            }
            $attribution = (new CallerAttributor($this->applicationNamespaces, $this->projectRoot))->attribute(true);
            if ($span->getAttribute('indexer.symbol_id') === null && $attribution['symbol_id'] !== null) {
                $span->setAttribute('indexer.symbol_id', $attribution['symbol_id']);
            }
            if ($span->getAttribute('indexer.caller.symbol_id') === null && $attribution['caller_symbol_id'] !== null) {
                $span->setAttribute('indexer.caller.symbol_id', $attribution['caller_symbol_id']);
            }
        } catch (\Throwable) {
            // Span processor callbacks are synchronous and must not affect the app.
        }
    }

    public function onEnd(ReadableSpanInterface $span): void
    {
    }

    public function forceFlush(?CancellationInterface $cancellation = null): bool
    {
        return true;
    }

    public function shutdown(?CancellationInterface $cancellation = null): bool
    {
        return true;
    }
}
