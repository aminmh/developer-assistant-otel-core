<?php

declare(strict_types=1);

namespace Indexr\Otel\Core;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\NoopTracerProvider;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Trace\Sampler\ParentBased;
use OpenTelemetry\SDK\Trace\Sampler\TraceIdRatioBasedSampler;
use OpenTelemetry\SDK\Trace\SamplerFactory;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Sdk;
use Throwable;

/** Installs an SDK provider only when the host has not configured one already. */
final class TelemetryBootstrap
{
    public const DEFAULT_EXPORT_TIMEOUT_MS = 2000;
    public const DEFAULT_EXPORT_MAX_RETRIES = 1;

    /** @param list<string> $applicationNamespaces */
    public static function register(array $applicationNamespaces = ['App'], ?string $projectRoot = null): bool
    {
        try {
            if (self::boolean('INDEXER_OTEL_ENABLED', true) !== true || self::boolean('OTEL_SDK_DISABLED', false) !== false) {
                return false;
            }
            $transportFactoryClass = 'OpenTelemetry\\Contrib\\Otlp\\OtlpHttpTransportFactory';
            $exporterClass = 'OpenTelemetry\\Contrib\\Otlp\\SpanExporter';
            if (!class_exists(Sdk::class) || !class_exists($transportFactoryClass) || !class_exists($exporterClass)) {
                return false;
            }
            if (!(Globals::tracerProvider() instanceof NoopTracerProvider)) {
                return false;
            }

            $protocol = self::environment('OTEL_EXPORTER_OTLP_PROTOCOL') ?? 'http/protobuf';
            if ($protocol !== 'http/protobuf') {
                return false;
            }
            $endpoint = self::environment('OTEL_EXPORTER_OTLP_TRACES_ENDPOINT');
            if ($endpoint === null || $endpoint === '') {
                $baseEndpoint = self::environment('OTEL_EXPORTER_OTLP_ENDPOINT');
                if ($baseEndpoint === null || $baseEndpoint === '') {
                    return false;
                }
                $endpoint = rtrim($baseEndpoint, '/') . '/v1/traces';
            }

            $headers = self::headers(self::environment('OTEL_EXPORTER_OTLP_HEADERS') ?? '', self::environment('OTEL_EXPORTER_OTLP_TRACES_HEADERS') ?? '');
            $transportFactory = new $transportFactoryClass();
            // Export runs at request shutdown inside the PHP worker; a short bound keeps an unreachable
            // Collector from holding FPM workers. OTEL_EXPORTER_OTLP_(TRACES_)TIMEOUT is in milliseconds.
            $timeoutMs = self::positiveInteger('OTEL_EXPORTER_OTLP_TRACES_TIMEOUT', self::positiveInteger('OTEL_EXPORTER_OTLP_TIMEOUT', self::DEFAULT_EXPORT_TIMEOUT_MS));
            $transport = $transportFactory->create(
                $endpoint,
                'application/x-protobuf',
                $headers,
                null,
                $timeoutMs / 1000,
                100,
                self::DEFAULT_EXPORT_MAX_RETRIES,
            );
            $exporter = new $exporterClass($transport);
            if (!$exporter instanceof SpanExporterInterface) {
                return false;
            }
            $queueSize = self::positiveInteger('OTEL_BSP_MAX_QUEUE_SIZE', 2048);
            $batchSize = min(self::positiveInteger('OTEL_BSP_MAX_EXPORT_BATCH_SIZE', 512), $queueSize);
            $processor = new BatchSpanProcessor(
                $exporter,
                \OpenTelemetry\API\Common\Time\Clock::getDefault(),
                $queueSize,
                self::positiveInteger('OTEL_BSP_SCHEDULE_DELAY', 5000),
                self::positiveInteger('OTEL_BSP_EXPORT_TIMEOUT', self::DEFAULT_EXPORT_TIMEOUT_MS),
                $batchSize,
            );

            $resourceValues = (new ResourceDetector($projectRoot))->detect();
            $resource = ResourceInfoFactory::defaultResource()->merge(ResourceInfo::create(Attributes::create($resourceValues)));
            $production = in_array(self::environment('APP_ENV'), ['production', 'prod'], true);
            $sampler = self::environment('OTEL_TRACES_SAMPLER') !== null
                ? (new SamplerFactory())->create()
                : new ParentBased(new TraceIdRatioBasedSampler($production ? 0.1 : 1.0));
            $provider = TracerProvider::builder()
                ->addSpanProcessor(new SpanSymbolIdProcessor($applicationNamespaces, $projectRoot))
                ->addSpanProcessor($processor)
                ->setResource($resource)
                ->setSampler($sampler)
                ->build();

            Sdk::builder()->setTracerProvider($provider)->setAutoShutdown(true)->buildAndRegisterGlobal();
            return true;
        } catch (Throwable) {
            // Malformed or unavailable telemetry setup is an opt-out, never an app boot failure.
            return false;
        }
    }

    private static function environment(string $name): ?string
    {
        $value = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function boolean(string $name, bool $default): ?bool
    {
        $value = self::environment($name);
        return $value === null ? $default : filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
    }

    private static function positiveInteger(string $name, int $default): int
    {
        $value = self::environment($name);
        return $value !== null && ctype_digit($value) && (int) $value > 0 ? (int) $value : $default;
    }

    /** @return array<string, string> */
    private static function headers(string $common, string $specific): array
    {
        $headers = [];
        foreach (array_filter([$common, $specific]) as $headerList) {
            foreach (explode(',', $headerList) as $pair) {
                $parts = explode('=', trim($pair), 2);
                if (count($parts) === 2 && trim($parts[0]) !== '') {
                    $headers[trim($parts[0])] = rawurldecode(trim($parts[1]));
                }
            }
        }
        return $headers;
    }
}
