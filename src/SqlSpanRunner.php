<?php

declare(strict_types=1);

namespace Indexr\Otel\Core;

use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use Throwable;

/** Starts bounded asynchronous-export-compatible spans around SQL execution. */
final class SqlSpanRunner
{
    /**
     * @param list<string> $applicationNamespaces
     * @param list<string> $tablePrefixes
     */
    public function __construct(
        private readonly string $connection,
        private readonly array $applicationNamespaces,
        private readonly string $projectRoot,
        private readonly array $tablePrefixes = [],
        private readonly ?string $databaseSystem = null,
        private readonly ?string $databaseNamespace = null,
        private readonly bool $callerAttribution = true,
    ) {
    }

    /** Opens and activates a span before the query runs; the caller finishes it when the query returns. */
    public function begin(string $sql, ?string $routeId = null): ?ActiveSpan
    {
        try {
            $span = $this->tracerSpan()->startSpan();
            if (!$span->isRecording()) {
                $span->end();
                return null;
            }
            $this->enrich($span, $sql, $routeId);

            return new ActiveSpan($span, $span->activate());
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Records an already completed query as a back-dated child of the active span.
     *
     * Used when no execution hook is available and the framework only reports the query afterwards
     * (for example Laravel's QueryExecuted event with its measured duration).
     */
    public function record(string $sql, float $elapsedMilliseconds, ?string $routeId = null, ?Throwable $failure = null): void
    {
        try {
            $end = Clock::getDefault()->now();
            $elapsed = is_finite($elapsedMilliseconds) && $elapsedMilliseconds > 0 ? (int) round($elapsedMilliseconds * 1_000_000) : 0;
            $span = $this->tracerSpan()->setStartTimestamp(max(0, $end - $elapsed))->startSpan();
            if (!$span->isRecording()) {
                $span->end($end);
                return;
            }
            $this->enrich($span, $sql, $routeId);
            if ($failure !== null) {
                $span->setAttribute('error.type', $failure::class);
                $span->setStatus(StatusCode::STATUS_ERROR);
            }
            $span->end($end);
        } catch (Throwable) {
            // Telemetry must never affect the query that was just executed.
        }
    }

    private function tracerSpan(): \OpenTelemetry\API\Trace\SpanBuilderInterface
    {
        return Globals::tracerProvider()->getTracer(SpanSymbolIdProcessor::OWN_TRACER)->spanBuilder('db.query')
            ->setSpanKind(SpanKind::KIND_CLIENT);
    }

    private function enrich(SpanInterface $span, string $sql, ?string $routeId): void
    {
        $query = (new SqlAnalyzer($this->connection, $this->tablePrefixes))->analyze($sql);
        $attributes = [
            'db.query.text' => $query->normalized,
            'indexer.table_ids' => $query->tableIds,
        ];
        if ($query->operation !== null) {
            $attributes['db.operation.name'] = $query->operation;
        }
        if ($this->databaseSystem !== null && preg_match('/\A[a-z][a-z0-9_]{0,31}\z/iD', $this->databaseSystem)) {
            $attributes['db.system.name'] = strtolower($this->databaseSystem);
        }
        if ($this->databaseNamespace !== null && preg_match('/\A[A-Za-z0-9_][A-Za-z0-9_.-]{0,127}\z/D', $this->databaseNamespace)) {
            $attributes['db.namespace'] = $this->databaseNamespace;
        }
        if ($routeId !== null && $routeId !== '') {
            $attributes['indexer.route_id'] = $routeId;
        }
        if ($this->callerAttribution) {
            $caller = (new CallerAttributor($this->applicationNamespaces, $this->projectRoot))->attribute(true);
            if ($caller['symbol_id'] !== null) {
                $attributes['indexer.symbol_id'] = $caller['symbol_id'];
            }
            if ($caller['caller_symbol_id'] !== null) {
                $attributes['indexer.caller.symbol_id'] = $caller['caller_symbol_id'];
            }
        }
        (new SpanEnricher())->enrich($span, $attributes);
    }
}
