<?php

declare(strict_types=1);

namespace Indexr\Otel\Core;

use OpenTelemetry\API\Trace\SpanInterface;

/** Adds only approved, canonical attributes to an active recorded span. */
final class SpanEnricher
{
    public function __construct(
        private readonly SymbolIdNormalizer $normalizer = new SymbolIdNormalizer(),
        private readonly Redactor $redactor = new Redactor(),
    ) {
    }

    /** @param array<string, mixed> $attributes */
    public function enrich(SpanInterface $span, array $attributes): void
    {
        try {
            if (!$span->isRecording()) {
                return;
            }
            $symbol = $attributes['indexer.symbol_id'] ?? null;
            if (is_array($symbol)) {
                $attributes['indexer.symbol_id'] = (string) $this->normalizer->normalize($symbol);
            }
            $safe = $this->redactor->attributes($attributes);
            foreach ($safe as $key => $value) {
                if ($key !== '') {
                    $span->setAttribute($key, $value);
                }
            }
        } catch (\Throwable) {
            // Telemetry enrichment must never affect the host application.
        }
    }

    /** @param array<string, mixed> $symbolInput */
    public function addSymbol(SpanInterface $span, array $symbolInput): void
    {
        try {
            $this->enrich($span, ['indexer.symbol_id' => (string) $this->normalizer->normalize($symbolInput)]);
        } catch (\Throwable) {
            // Invalid or incomplete frame metadata is intentionally dropped.
        }
    }
}
