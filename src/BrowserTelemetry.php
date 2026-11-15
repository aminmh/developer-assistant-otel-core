<?php

declare(strict_types=1);

namespace Indexr\Otel\Core;

use Throwable;

/**
 * Relays browser telemetry from the application's own origin to the Collector.
 *
 * The browser snippet (`resources/browser.js`) posts a small JSON batch to a path on the application.
 * This class validates every field against a fixed shape, stamps the application's resource (project,
 * revision), and forwards the spans as OTLP/JSON. The browser never learns the Collector's address,
 * and nothing it sends is copied through unchecked: a span carries a route template, timings, Web
 * Vitals, and an error class name, and no free text.
 *
 * The spans keep the trace and span IDs the browser generated, because the same IDs travelled in the
 * `traceparent` header of the page's requests and are recorded on each request span
 * (`ServerRequestSpan::tagBrowserView`). That is why this does not go through the SDK tracer, which
 * would assign new span IDs.
 */
final class BrowserTelemetry
{
    public const MAX_BODY_BYTES = 65536;
    public const MAX_SPANS = 30;
    public const SCOPE_NAME = 'indexr.browser';
    private const MAX_DURATION_MS = 1_800_000;
    private const MAX_PAST_MS = 3_600_000;
    private const MAX_FUTURE_MS = 300_000;
    private const SPAN_NAMES = ['page' => 'browser.page', 'interaction' => 'browser.interaction', 'error' => 'browser.error'];
    private const SPAN_KIND_CLIENT = 3;
    private const STATUS_ERROR = 2;

    /**
     * Whether a request comes from one of the application's own pages: the browser marks it
     * same-origin (a header scripts cannot set) and it carries the application's session cookie.
     *
     * Only such requests may post browser spans or name the screen view they belong to.
     */
    public static function trustsRequest(mixed $secFetchSite, bool $hasSessionCookie): bool
    {
        return $hasSessionCookie && $secFetchSite === 'same-origin';
    }

    /**
     * The screen view named by a W3C `traceparent` header, or null when the header is not well formed.
     *
     * @return array{trace_id: string, span_id: string}|null
     */
    public static function viewOf(mixed $traceparent): ?array
    {
        if (is_array($traceparent)) {
            $traceparent = $traceparent[0] ?? null;
        }
        if (!is_string($traceparent) || preg_match('/\A00-([0-9a-f]{32})-([0-9a-f]{16})-0[01]\z/', $traceparent, $match) !== 1
            || trim($match[1], '0') === '' || trim($match[2], '0') === '') {
            return null;
        }
        return ['trace_id' => $match[1], 'span_id' => $match[2]];
    }

    /**
     * @return array{spans: list<array<string, int|float|string>>, frontend: array{project_id: string, revision_id: string}|null}
     */
    public static function parse(string $body, ?int $nowMs = null): array
    {
        $empty = ['spans' => [], 'frontend' => null];
        if ($body === '' || strlen($body) > self::MAX_BODY_BYTES) {
            return $empty;
        }
        try {
            $data = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return $empty;
        }
        if (!is_array($data) || ($data['v'] ?? null) !== 1 || !is_array($data['spans'] ?? null)) {
            return $empty;
        }
        $nowMs ??= (int) (microtime(true) * 1000);
        $spans = [];
        foreach (array_slice(array_values($data['spans']), 0, self::MAX_SPANS) as $raw) {
            $span = is_array($raw) ? self::span($raw, $nowMs) : null;
            if ($span !== null) {
                $spans[] = $span;
            }
        }
        $frontend = null;
        $given = $data['frontend'] ?? null;
        if (is_array($given) && is_string($given['project_id'] ?? null) && is_string($given['revision_id'] ?? null)
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/', $given['project_id']) === 1
            && preg_match('/^rev_[0-9a-f]{32}$/', $given['revision_id']) === 1) {
            $frontend = ['project_id' => $given['project_id'], 'revision_id' => $given['revision_id']];
        }
        return ['spans' => $spans, 'frontend' => $frontend];
    }

    /**
     * @param array<mixed> $raw
     * @return array<string, int|float|string>|null
     */
    private static function span(array $raw, int $nowMs): ?array
    {
        $type = $raw['type'] ?? null;
        $traceId = $raw['trace_id'] ?? null;
        $spanId = $raw['span_id'] ?? null;
        $route = $raw['route'] ?? null;
        $start = $raw['start_ms'] ?? null;
        $duration = $raw['duration_ms'] ?? null;
        if (!is_string($type) || !isset(self::SPAN_NAMES[$type])
            || !is_string($traceId) || preg_match('/^[0-9a-f]{32}$/', $traceId) !== 1 || trim($traceId, '0') === ''
            || !is_string($spanId) || preg_match('/^[0-9a-f]{16}$/', $spanId) !== 1 || trim($spanId, '0') === ''
            || !is_string($route) || preg_match('/^[A-Za-z0-9_\-.\/:{}*\[\]@ ()]{1,200}$/', $route) !== 1
            || !is_int($start) || $start < $nowMs - self::MAX_PAST_MS || $start > $nowMs + self::MAX_FUTURE_MS
            || !is_int($duration) || $duration < 0 || $duration > self::MAX_DURATION_MS) {
            return null;
        }
        $span = ['type' => $type, 'trace_id' => $traceId, 'span_id' => $spanId, 'route' => $route,
            'start_ms' => $start, 'duration_ms' => $duration];
        $parent = $raw['parent_span_id'] ?? null;
        if ($type !== 'page') {
            // A child must name its page span; otherwise it would be a second root in the trace.
            if (!is_string($parent) || preg_match('/^[0-9a-f]{16}$/', $parent) !== 1 || $parent === $spanId) {
                return null;
            }
            $span['parent_span_id'] = $parent;
        }
        if ($type === 'page') {
            foreach (['lcp_ms', 'inp_ms'] as $key) {
                if (is_int($raw[$key] ?? null) && $raw[$key] >= 0 && $raw[$key] <= 600_000) {
                    $span[$key] = $raw[$key];
                }
            }
            $cls = $raw['cls'] ?? null;
            if ((is_int($cls) || is_float($cls)) && $cls >= 0 && $cls <= 100) {
                $span['cls'] = round((float) $cls, 3);
            }
        }
        if ($type === 'error') {
            $errorType = $raw['error_type'] ?? null;
            $span['error_type'] = is_string($errorType) && preg_match('/^[A-Za-z_$][A-Za-z0-9_$]{0,79}$/', $errorType) === 1
                ? $errorType : 'Error';
        }
        return $span;
    }

    /**
     * The OTLP/JSON export request for a parsed batch.
     *
     * @param array{spans: list<array<string, int|float|string>>, frontend: array{project_id: string, revision_id: string}|null} $batch
     * @param array<string, string|bool> $resource The application's own resource attributes.
     * @return array<string, mixed>
     */
    public static function payload(array $batch, array $resource): array
    {
        $spans = [];
        foreach ($batch['spans'] as $span) {
            $attributes = [self::attribute('browser.route', (string) $span['route'])];
            foreach (['lcp_ms' => 'browser.vital.lcp_ms', 'inp_ms' => 'browser.vital.inp_ms', 'cls' => 'browser.vital.cls'] as $key => $name) {
                if (isset($span[$key])) {
                    $attributes[] = self::attribute($name, $span[$key]);
                }
            }
            if (isset($span['error_type'])) {
                $attributes[] = self::attribute('error.type', (string) $span['error_type']);
            }
            if ($batch['frontend'] !== null) {
                $attributes[] = self::attribute('browser.frontend.project_id', $batch['frontend']['project_id']);
                $attributes[] = self::attribute('browser.frontend.revision_id', $batch['frontend']['revision_id']);
            }
            $start = (int) $span['start_ms'];
            $entry = [
                'traceId' => $span['trace_id'],
                'spanId' => $span['span_id'],
                'name' => self::SPAN_NAMES[(string) $span['type']],
                'kind' => self::SPAN_KIND_CLIENT,
                // OTLP/JSON carries 64-bit integers as decimal strings.
                'startTimeUnixNano' => $start . '000000',
                'endTimeUnixNano' => ($start + (int) $span['duration_ms']) . '000000',
                'attributes' => $attributes,
            ];
            if (isset($span['parent_span_id'])) {
                $entry['parentSpanId'] = $span['parent_span_id'];
            }
            if ($span['type'] === 'error') {
                $entry['status'] = ['code' => self::STATUS_ERROR];
            }
            $spans[] = $entry;
        }
        $resourceAttributes = [];
        foreach ($resource as $key => $value) {
            $resourceAttributes[] = self::attribute($key, $value);
        }
        return ['resourceSpans' => [[
            'resource' => ['attributes' => $resourceAttributes],
            'scopeSpans' => [['scope' => ['name' => self::SCOPE_NAME], 'spans' => $spans]],
        ]]];
    }

    /** @return array{key: string, value: array<string, bool|float|string>} */
    private static function attribute(string $key, bool|int|float|string $value): array
    {
        $typed = match (true) {
            is_bool($value) => ['boolValue' => $value],
            is_int($value) => ['intValue' => (string) $value],
            is_float($value) => ['doubleValue' => $value],
            default => ['stringValue' => $value],
        };
        return ['key' => $key, 'value' => $typed];
    }

    /**
     * The Collector's trace endpoint from the standard OTLP environment variables, or null.
     *
     * @param array<string, string>|null $environment Read instead of the process environment (for tests).
     */
    public static function endpoint(?array $environment = null): ?string
    {
        $read = static function (string $name) use ($environment): ?string {
            $value = $environment !== null ? ($environment[$name] ?? null) : ($_SERVER[$name] ?? $_ENV[$name] ?? getenv($name));
            return is_string($value) && $value !== '' ? $value : null;
        };
        $endpoint = $read('OTEL_EXPORTER_OTLP_TRACES_ENDPOINT');
        if ($endpoint === null) {
            $base = $read('OTEL_EXPORTER_OTLP_ENDPOINT');
            $endpoint = $base === null ? null : rtrim($base, '/') . '/v1/traces';
        }
        return $endpoint !== null && preg_match('#^https?://[^\s]+$#', $endpoint) === 1 ? $endpoint : null;
    }

    /**
     * Validates and forwards one browser batch. Returns how many spans were sent; 0 when the batch
     * was empty, invalid, or the Collector did not accept it. Never throws.
     *
     * @param array<string, string|bool> $resource
     * @param (callable(string, string): bool)|null $send Transport override for tests: (endpoint, json) -> accepted.
     */
    public static function relay(string $body, array $resource, ?string $endpoint, ?callable $send = null): int
    {
        try {
            if ($endpoint === null || !isset($resource['indexer.project_id'], $resource['indexer.revision_id'])) {
                return 0;
            }
            $batch = self::parse($body);
            if ($batch['spans'] === []) {
                return 0;
            }
            $json = json_encode(self::payload($batch, $resource), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            return ($send ?? self::post(...))($endpoint, $json) ? count($batch['spans']) : 0;
        } catch (Throwable) {
            return 0;
        }
    }

    /** A short, bounded POST: an unreachable Collector must not hold a PHP worker. */
    private static function post(string $endpoint, string $json): bool
    {
        if (function_exists('curl_init')) {
            $handle = curl_init($endpoint);
            if ($handle === false) {
                return false;
            }
            curl_setopt_array($handle, [
                CURLOPT_POST => true, CURLOPT_POSTFIELDS => $json, CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_CONNECTTIMEOUT_MS => 500, CURLOPT_TIMEOUT_MS => 1500, CURLOPT_FOLLOWLOCATION => false,
            ]);
            curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            curl_close($handle);
            return $status >= 200 && $status < 300;
        }
        $context = stream_context_create(['http' => [
            'method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => $json,
            'timeout' => 1.5, 'ignore_errors' => true, 'follow_location' => 0,
        ]]);
        $result = @file_get_contents($endpoint, false, $context);
        $line = $http_response_header[0] ?? '';
        return $result !== false && preg_match('#^HTTP/\S+ 2\d\d#', $line) === 1;
    }

    /** The browser snippet with its configuration prepended, ready to serve as JavaScript. */
    public static function script(string $endpointPath, float $sampleRate, ?string $frontendProjectId = null, ?string $frontendRevisionId = null): string
    {
        $config = ['endpoint' => '/' . ltrim($endpointPath, '/'), 'sampleRate' => max(0.0, min(1.0, $sampleRate))];
        if ($frontendProjectId !== null && $frontendRevisionId !== null) {
            $config['frontend'] = ['projectId' => $frontendProjectId, 'revisionId' => $frontendRevisionId];
        }
        $source = (string) file_get_contents(dirname(__DIR__) . '/resources/browser.js');
        // JSON_HEX_TAG keeps "</script>" out of the output even if a value contained it.
        $json = json_encode($config, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_UNESCAPED_SLASHES);
        return 'window.__INDEXR_BROWSER__ = Object.assign(' . $json . ", window.__INDEXR_BROWSER__ || {});\n" . $source;
    }
}
