<?php

declare(strict_types=1);

namespace Indexr\Otel\Core;

use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\Context\ContextInterface;
use Throwable;

/**
 * One `http.server` span per request, independent of the framework.
 *
 * The span starts as early as the adapter can hook in, back-dated to the web server's request start so
 * bootstrap and global middleware are inside it. Its duration ends when the response has been sent, but
 * its context stays active until the adapter's last termination hook, so post-response work (session
 * writes, debug-bar storage) stays in the request's trace instead of becoming separate root traces.
 */
final class ServerRequestSpan
{
    /** `http.route` for requests that matched no route (404/405 and errors raised before routing). */
    public const UNMATCHED_ROUTE = '(unmatched)';
    /** A request start older than this is treated as stale (long-lived workers reuse server variables). */
    private const MAX_START_AGE_NANOS = 300_000_000_000;

    private ?ActiveSpan $span = null;
    /** True from begin() until finish(), even when the sampler chose not to record the span. */
    private bool $begun = false;
    private string $method = 'GET';
    private bool $routed = false;
    private ?int $responseSentAt = null;

    public function __construct(private readonly SymbolIdNormalizer $normalizer = new SymbolIdNormalizer())
    {
    }

    public function isActive(): bool
    {
        return $this->span !== null;
    }

    public function activeSpan(): ?ActiveSpan
    {
        return $this->span;
    }

    /** Whether this request already made its sampling decision; a second begin() would re-roll it. */
    public function hasBegun(): bool
    {
        return $this->begun;
    }

    /**
     * Starts the span unless one is already active for this request.
     *
     * @param mixed $requestTimeFloat The SAPI's REQUEST_TIME_FLOAT for this request, if known.
     */
    public function begin(string $method, mixed $requestTimeFloat = null, ?string $scheme = null, ?ContextInterface $parent = null): void
    {
        if ($this->begun) {
            return;
        }
        $this->begun = true;
        $this->method = self::method($method);
        $this->routed = false;
        $this->responseSentAt = null;
        $attributes = ['http.request.method' => $this->method];
        if ($scheme === 'http' || $scheme === 'https') {
            $attributes['url.scheme'] = $scheme;
        }
        $this->span = (new SpanRunner())->begin(
            'http.server',
            $attributes,
            SpanKind::KIND_SERVER,
            $parent,
            self::startNanos($requestTimeFloat),
        );
    }

    /**
     * Records the matched route and controller; returns the route ID, or null when it cannot be normalized.
     *
     * @param list<string> $routeMethods Methods the route accepts.
     */
    public function route(string $template, array $routeMethods, ?string $controller = null): ?string
    {
        $this->routed = true;
        $template = '/' . ltrim($template, '/');
        $attributes = ['http.route' => $template];
        $routeId = null;
        try {
            $routeId = (string) $this->normalizer->normalize([
                'kind' => 'route',
                'method' => self::routeMethod($this->method, $routeMethods),
                'template' => $template,
            ]);
            $attributes['indexer.route_id'] = $routeId;
        } catch (Throwable) {
        }
        if ($controller !== null && $controller !== '') {
            try {
                $attributes['indexer.symbol_id'] = (string) $this->normalizer->normalize(['kind' => 'controller', 'controller' => $controller]);
            } catch (Throwable) {
            }
        }
        $this->span?->enrich($attributes);
        return $routeId;
    }

    /** Whether route() has recorded the matched route for this request. */
    public function isRouted(): bool
    {
        return $this->routed;
    }

    public const BENCHMARK_HEADER = 'X-DevAssistant-Benchmark';

    /**
     * Tag the request with the Developer Assistant benchmark run that sent it, so Runtime can show
     * what a load test did. Only a lowercase UUID is accepted; any other header value is ignored.
     */
    public function tagBenchmark(mixed $runId): void
    {
        if (is_array($runId)) {
            $runId = $runId[0] ?? null;
        }
        if (is_string($runId) && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/', $runId) === 1) {
            $this->span?->enrich(['indexer.benchmark_run_id' => $runId]);
        }
    }

    /**
     * Record the browser screen view that sent this request, from the `traceparent` header the browser
     * snippet adds. The request is not made a child of that view: it stays its own trace and carries
     * the view's IDs, so the two can be shown together without one request's trace holding another's.
     */
    public function tagBrowserView(mixed $traceparent): void
    {
        $view = BrowserTelemetry::viewOf($traceparent);
        if ($view !== null) {
            $this->span?->enrich(['indexer.browser.trace_id' => $view['trace_id'], 'indexer.browser.span_id' => $view['span_id']]);
        }
    }

    /** @param array<string, mixed> $attributes */
    public function enrich(array $attributes): void
    {
        $this->span?->enrich($attributes);
    }

    public function respond(int $statusCode): void
    {
        if ($statusCode < 100 || $statusCode > 599) {
            return;
        }
        $this->span?->enrich(['http.response.status_code' => $statusCode]);
        if ($statusCode >= 500) {
            $this->span?->markError((string) $statusCode);
        }
    }

    public function fail(Throwable $failure): void
    {
        $this->span?->markError($failure::class);
    }

    /** Fixes the span's end time at the moment the response left the application. First call wins. */
    public function markResponseSent(): void
    {
        if ($this->span !== null) {
            $this->responseSentAt ??= Clock::getDefault()->now();
        }
    }

    /** Ends the span at the recorded response time (or now) and detaches its context. */
    public function finish(): void
    {
        $span = $this->span;
        $this->span = null;
        $this->begun = false;
        if ($span === null) {
            $this->responseSentAt = null;
            $this->routed = false;
            return;
        }
        if (!$this->routed) {
            $span->enrich(['http.route' => self::UNMATCHED_ROUTE]);
        }
        $span->finish(null, $this->responseSentAt);
        $this->responseSentAt = null;
        $this->routed = false;
    }

    public static function method(string $method): string
    {
        $method = strtoupper(trim($method));
        return preg_match('/\A[A-Z]{1,16}\z/D', $method) ? $method : '_OTHER';
    }

    /**
     * The method recorded in the route ID: the request's own method when the route accepts it (HEAD counts
     * as GET), otherwise the route's first real method.
     *
     * @param list<string> $routeMethods
     */
    public static function routeMethod(string $requestMethod, array $routeMethods): string
    {
        $methods = array_values(array_unique(array_map(static fn (string $value): string => strtoupper($value), $routeMethods)));
        $real = array_values(array_diff($methods, ['HEAD', 'OPTIONS']));
        $requestMethod = strtoupper($requestMethod);
        if ($requestMethod === 'HEAD' && in_array('GET', $methods, true)) {
            return 'GET';
        }
        if (in_array($requestMethod, $real, true)) {
            return $requestMethod;
        }
        return $real[0] ?? $methods[0] ?? ($requestMethod !== '' ? $requestMethod : 'GET');
    }

    /** Converts REQUEST_TIME_FLOAT to epoch nanoseconds when it is plausible for the current request. */
    public static function startNanos(mixed $requestTimeFloat, ?int $nowNanos = null): ?int
    {
        if (!is_float($requestTimeFloat) && !is_int($requestTimeFloat) && !(is_string($requestTimeFloat) && is_numeric($requestTimeFloat))) {
            return null;
        }
        $start = (int) round((float) $requestTimeFloat * 1_000_000_000);
        $now = $nowNanos ?? Clock::getDefault()->now();
        if ($start <= 0 || $start > $now || $now - $start > self::MAX_START_AGE_NANOS) {
            return null;
        }
        return $start;
    }
}
