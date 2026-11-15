<?php

declare(strict_types=1);

namespace Indexr\Otel\Core;

use Throwable;

/** Samples request/job stacks and emits only bounded canonical-symbol profiles. */
final class ExcimerProfileSession
{
    /** @var \ExcimerProfiler|null */
    private mixed $profiler = null;
    /** @var \ExcimerLog|null */
    private mixed $capturedLog = null;
    private int $startedAtNs = 0;
    private string $eventType = 'EXCIMER_REAL';
    private const PERIOD_SECONDS = 0.005;
    private const MAX_COLLAPSED_BYTES = 262144;

    /** @param list<string> $applicationNamespaces */
    public function __construct(private readonly array $applicationNamespaces = ['App'])
    {
    }

    public function start(): void
    {
        $this->reset();
        $profiler = null;
        try {
            if (!self::boolean('INDEXER_OTEL_PROFILER', true) || !class_exists('ExcimerProfiler')) {
                return;
            }
            $event = strtoupper(self::environment('INDEXER_OTEL_EXCIMER_EVENT') ?? 'EXCIMER_REAL');
            if (!in_array($event, ['EXCIMER_REAL', 'EXCIMER_CPU'], true)
                || !defined($event)) {
                return;
            }
            $profiler = new \ExcimerProfiler();
            $profiler->setPeriod(self::PERIOD_SECONDS);
            $profiler->setMaxDepth(128);
            $profiler->setEventType((int) constant($event));
            $profiler->setFlushCallback(function (\ExcimerLog $log) use ($profiler): void {
                $this->capturedLog = $log;
                $profiler->stop();
            }, 10000);
            $profiler->start();
            $this->profiler = $profiler;
            $this->eventType = $event;
            $this->startedAtNs = hrtime(true);
        } catch (Throwable) {
            if ($profiler !== null) {
                try {
                    $profiler->stop();
                } catch (Throwable) {
                }
                try {
                    $profiler->clearFlushCallback();
                } catch (Throwable) {
                }
            }
            $this->reset();
        }
    }

    public function reset(): void
    {
        $profiler = $this->profiler;
        $this->profiler = null;
        $this->capturedLog = null;
        $this->startedAtNs = 0;
        if ($profiler === null) {
            return;
        }
        try {
            $profiler->stop();
        } catch (Throwable) {
        }
        try {
            $profiler->clearFlushCallback();
        } catch (Throwable) {
        }
    }

    /**
     * @param array<string, string|bool> $resources
     * @return array{profile_id: string, trace_id: string, span_id: string, duration_ms: float, event_type: string, sample_period_ms: float, samples_collapsed: string, top_frames: list<string>, project_id: string, revision_id: string, environment: string, service_name: string, resource_attributes: array<string, string|bool>}|null
     */
    public function stop(string $traceId, string $spanId, int $statusCode, array $resources): ?array
    {
        $profiler = $this->profiler;
        $startedAtNs = $this->startedAtNs;
        $this->profiler = null;
        $this->startedAtNs = 0;
        if ($profiler === null || $startedAtNs <= 0) {
            $this->capturedLog = null;
            return null;
        }
        try {
            $profiler->stop();
            $profiler->clearFlushCallback();
            $log = $this->capturedLog ?? $profiler->getLog();
            $this->capturedLog = null;
            $raw = $log->formatCollapsed();
            $durationMs = round((hrtime(true) - $startedAtNs) / 1_000_000, 3);
            if ($raw === '' || !is_finite($durationMs)) {
                return null;
            }
            $collapsed = $this->canonicalCollapsed($raw);
            if ($collapsed === '' || strlen($collapsed) > self::MAX_COLLAPSED_BYTES || !$this->retain($statusCode, $durationMs)) {
                return null;
            }
            foreach (['indexer.project_id', 'indexer.revision_id', 'deployment.environment.name'] as $required) {
                if (!isset($resources[$required]) || !is_string($resources[$required]) || $resources[$required] === '') {
                    return null;
                }
            }
            if (!preg_match('/\\A[a-f0-9]{32}\\z/i', $traceId) || !preg_match('/\\A[a-f0-9]{16}\\z/i', $spanId)
                || preg_match('/\\A0+\\z/', $traceId) || preg_match('/\\A0+\\z/', $spanId)) {
                return null;
            }
            $topFrames = $this->topFrames($collapsed);
            if ($topFrames === []) {
                return null;
            }
            $serviceName = $resources['service.name'] ?? null;
            if (!is_string($serviceName) || $serviceName === '') {
                $serviceName = 'php';
            }
            return [
                'profile_id' => 'prof_' . bin2hex(random_bytes(12)),
                'trace_id' => strtolower($traceId),
                'span_id' => strtolower($spanId),
                'duration_ms' => $durationMs,
                'event_type' => $this->eventType,
                'sample_period_ms' => self::PERIOD_SECONDS * 1000,
                'samples_collapsed' => $collapsed,
                'top_frames' => $topFrames,
                'project_id' => $resources['indexer.project_id'],
                'revision_id' => $resources['indexer.revision_id'],
                'environment' => $resources['deployment.environment.name'],
                'service_name' => $serviceName,
                'resource_attributes' => $resources,
            ];
        } catch (Throwable) {
            try {
                $profiler->stop();
            } catch (Throwable) {
            }
            try {
                $profiler->clearFlushCallback();
            } catch (Throwable) {
            }
            $this->capturedLog = null;
            return null;
        }
    }

    private function canonicalCollapsed(string $raw): string
    {
        $normalizer = new SymbolIdNormalizer();
        $lines = [];
        $outputBytes = 0;
        foreach (preg_split('/\\R/', $raw) ?: [] as $line) {
            if (count($lines) >= 2000) {
                break;
            }
            if (!preg_match('/\\A(.+?)\\s+(\\d+)\\z/', trim($line), $matches)) {
                continue;
            }
            $frames = [];
            foreach (explode(';', $matches[1]) as $frame) {
                $frame = trim($frame);
                $separator = strrpos($frame, '::');
                if ($separator === false) {
                    continue;
                }
                $class = ltrim(substr($frame, 0, $separator), '\\');
                $method = substr($frame, $separator + 2);
                if (!self::inApplicationNamespace($class, $this->applicationNamespaces)) {
                    continue;
                }
                try {
                    $frames[] = (string) $normalizer->normalize(['kind' => 'method', 'class' => $class, 'method' => $method]);
                } catch (Throwable) {
                    continue;
                }
            }
            if ($frames !== []) {
                $folded = implode(';', $frames) . ' ' . max(1, min(1_000_000, (int) $matches[2]));
                $nextBytes = strlen($folded) + ($lines === [] ? 0 : 1);
                if ($outputBytes + $nextBytes > self::MAX_COLLAPSED_BYTES) {
                    break;
                }
                $lines[] = $folded;
                $outputBytes += $nextBytes;
            }
        }
        return implode("\n", $lines);
    }

    /** @return list<string> */
    private function topFrames(string $collapsed): array
    {
        $counts = [];
        foreach (explode("\n", $collapsed) as $line) {
            if (!preg_match('/\\A(.+)\\s+(\\d+)\\z/', $line, $matches)) {
                continue;
            }
            foreach (explode(';', $matches[1]) as $frame) {
                $counts[$frame] = ($counts[$frame] ?? 0) + (int) $matches[2];
            }
        }
        arsort($counts);
        return array_slice(array_keys($counts), 0, 10);
    }

    private function retain(int $statusCode, float $durationMs): bool
    {
        if ($statusCode >= 500 || $durationMs > self::positiveFloat('INDEXER_OTEL_PROFILE_SLA_MS', 200.0)) {
            return true;
        }
        $rate = max(0.0, min(1.0, self::positiveFloat('INDEXER_OTEL_PROFILE_SAMPLE_RATE', 0.01, allowZero: true)));
        return $rate > 0.0 && random_int(0, 1_000_000) < (int) round($rate * 1_000_000);
    }

    /** @param list<string> $namespaces */
    private static function inApplicationNamespace(string $class, array $namespaces): bool
    {
        foreach ($namespaces as $namespace) {
            if (trim($namespace) === '') {
                continue;
            }
            $prefix = rtrim(trim($namespace), '*\\');
            if ($prefix !== '' && ($class === $prefix || str_starts_with($class, $prefix . '\\'))) {
                return true;
            }
        }
        return false;
    }

    private static function environment(string $name): ?string
    {
        $value = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function boolean(string $name, bool $default): bool
    {
        $value = self::environment($name);
        return $value === null ? $default : filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) === true;
    }

    private static function positiveFloat(string $name, float $default, bool $allowZero = false): float
    {
        $value = self::environment($name);
        if ($value === null || !is_numeric($value)) {
            return $default;
        }
        $number = (float) $value;
        return is_finite($number) && ($allowZero ? $number >= 0 : $number > 0) ? $number : $default;
    }
}
