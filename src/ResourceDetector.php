<?php

declare(strict_types=1);

namespace Indexr\Otel\Core;

/** Detects only the allow-listed resource fields used to join telemetry to Indexr. */
final class ResourceDetector
{
    private const TELEMETRY_SCHEMA_VERSION = 'indexer-telemetry-v2';
    /** @var array{revision: ?string, dirty: ?bool}|null */
    private ?array $gitMetadata = null;

    public function __construct(
        private readonly ?string $projectRoot = null,
        private readonly string $cgroupPath = '/proc/self/cgroup',
        private readonly string $mountInfoPath = '/proc/self/mountinfo',
    ) {
    }

    /**
     * @param array<string, string|false|null> $environment
     * @return array<string, string|bool>
     */
    public function detect(array $environment = []): array
    {
        $environment = $environment === [] ? self::processEnvironment() : $environment;
        $attributes = self::parseResourceAttributes($environment['OTEL_RESOURCE_ATTRIBUTES'] ?? null);
        $result = ['indexer.schema.version' => self::TELEMETRY_SCHEMA_VERSION];

        $result = self::copyString($result, $attributes, 'service.name');
        if (!isset($result['service.name'])) {
            $result = self::copyEnvironmentString($result, $environment, 'service.name', 'OTEL_SERVICE_NAME');
        }
        $result = self::copyString($result, $attributes, 'service.version');
        $result = self::copyString($result, $attributes, 'indexer.project_id');
        if (!isset($result['indexer.project_id'])) {
            $result = self::copyEnvironmentString($result, $environment, 'indexer.project_id', 'INDEXER_PROJECT_ID');
        }
        $revisionId = self::validRevisionId($attributes['indexer.revision_id'] ?? null)
            ?? self::validRevisionId($environment['INDEXER_REVISION_ID'] ?? null);
        if ($revisionId !== null) {
            $result['indexer.revision_id'] = $revisionId;
        }
        $result = self::copyString($result, $attributes, 'container.image.name');
        $result = self::copyString($result, $attributes, 'deployment.environment.name');
        if (!isset($result['deployment.environment.name'])) {
            $result = self::copyEnvironmentString($result, $environment, 'deployment.environment.name', 'APP_ENV');
        }

        $revision = self::validRevision($attributes['vcs.ref.head.revision'] ?? null);
        if ($revision === null) {
            $revision = self::validRevision($environment['INDEXER_COMMIT_SHA'] ?? null);
        }
        if ($revision === null) {
            $git = $this->gitMetadata();
            $revision = $git['revision'];
        }
        if ($revision !== null) {
            $result['vcs.ref.head.revision'] = $revision;
        }

        $containerId = self::validContainerId($attributes['container.id'] ?? null)
            ?? $this->detectContainerId();
        if ($containerId !== null) {
            $result['container.id'] = $containerId;
        }

        $dirty = self::booleanValue($attributes['indexer.code_dirty'] ?? null);
        if ($dirty === null) {
            $dirty = $this->gitMetadata()['dirty'];
        }
        if ($dirty !== null) {
            $result['indexer.code_dirty'] = $dirty;
        }

        $ratio = self::validRatio($attributes['indexer.sampling.ratio'] ?? null) ?? self::samplingRatio($environment);
        if ($ratio !== null) {
            $result['indexer.sampling.ratio'] = $ratio;
        }

        return $result;
    }

    /**
     * Head-sampling ratio the SDK applies to new traces, as a decimal string; null when the sampler is unknown.
     *
     * Mirrors TelemetryBootstrap: without OTEL_TRACES_SAMPLER the package samples 10% in production and
     * everything elsewhere. Runtime views use it to tell recorded counts from real traffic.
     *
     * @param array<string, string|false|null> $environment
     */
    public static function samplingRatio(array $environment): ?string
    {
        $sampler = strtolower(trim((string) ($environment['OTEL_TRACES_SAMPLER'] ?? '')));
        if ($sampler === '') {
            return in_array($environment['APP_ENV'] ?? null, ['production', 'prod'], true) ? '0.1' : '1';
        }
        return match ($sampler) {
            'always_on', 'parentbased_always_on' => '1',
            'always_off', 'parentbased_always_off' => '0',
            'traceidratio', 'parentbased_traceidratio' => self::validRatio($environment['OTEL_TRACES_SAMPLER_ARG'] ?? null) ?? '1',
            default => null,
        };
    }

    private static function validRatio(mixed $value): ?string
    {
        if (!is_string($value) || !is_numeric(trim($value))) {
            return null;
        }
        $ratio = (float) trim($value);
        if ($ratio < 0.0 || $ratio > 1.0) {
            return null;
        }
        $formatted = rtrim(rtrim(sprintf('%.6F', $ratio), '0'), '.');
        return $formatted === '' ? '0' : $formatted;
    }

    /** @return array<string, string> */
    private static function parseResourceAttributes(string|false|null $raw): array
    {
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $allowed = [
            'service.name', 'service.version', 'vcs.ref.head.revision', 'indexer.project_id', 'indexer.revision_id',
            'container.id', 'container.image.name', 'deployment.environment.name', 'indexer.code_dirty',
            'indexer.schema.version', 'indexer.sampling.ratio',
        ];
        $result = [];
        foreach (explode(',', $raw) as $pair) {
            $parts = explode('=', $pair, 2);
            if (count($parts) !== 2) {
                continue;
            }
            $key = trim($parts[0]);
            $value = rawurldecode(trim($parts[1]));
            if (in_array($key, $allowed, true) && $value !== '') {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * @param array<string, string|bool> $result
     * @param array<string, string> $source
     * @return array<string, string|bool>
     */
    private static function copyString(array $result, array $source, string $key): array
    {
        if (isset($source[$key]) && $source[$key] !== '') {
            $result[$key] = $source[$key];
        }

        return $result;
    }

    /**
     * @param array<string, string|bool> $result
     * @param array<string, string|false|null> $environment
     * @return array<string, string|bool>
     */
    private static function copyEnvironmentString(array $result, array $environment, string $attribute, string $key): array
    {
        $value = $environment[$key] ?? null;
        if (is_string($value) && trim($value) !== '') {
            $result[$attribute] = trim($value);
        }

        return $result;
    }

    private static function validRevisionId(string|false|null $value): ?string
    {
        return is_string($value) && preg_match('/\Arev_[a-f0-9]{32}\z/D', trim($value))
            ? trim($value)
            : null;
    }

    private static function validRevision(string|false|null $value): ?string
    {
        return is_string($value) && preg_match('/\A[a-fA-F0-9]{7,64}\z/D', trim($value))
            ? strtolower(trim($value))
            : null;
    }

    private static function validContainerId(string|false|null $value): ?string
    {
        return is_string($value) && preg_match('/\A[a-fA-F0-9]{12,64}\z/D', trim($value))
            ? strtolower(trim($value))
            : null;
    }

    private static function booleanValue(string|false|null $value): ?bool
    {
        if (!is_string($value)) {
            return null;
        }
        return match (strtolower(trim($value))) {
            'true', '1' => true,
            'false', '0' => false,
            default => null,
        };
    }

    private function detectContainerId(): ?string
    {
        foreach ([$this->cgroupPath, $this->mountInfoPath] as $path) {
            $contents = @file_get_contents($path);
            if (!is_string($contents)) {
                continue;
            }
            if (preg_match('/(?:^|[\/:-])([a-fA-F0-9]{64})(?:\.scope)?(?:$|[\/\s])/m', $contents, $matches)) {
                return strtolower($matches[1]);
            }
        }

        return null;
    }

    /** @return array{revision: ?string, dirty: ?bool} */
    private function gitMetadata(): array
    {
        if ($this->gitMetadata !== null) {
            return $this->gitMetadata;
        }

        $root = $this->projectRoot ?? getcwd();
        if (!is_string($root) || $root === '') {
            return $this->gitMetadata = ['revision' => null, 'dirty' => null];
        }
        $revisionOutput = self::runGit($root, 'rev-parse', '--verify', 'HEAD');
        $revision = self::validRevision($revisionOutput);
        if ($revision === null) {
            return $this->gitMetadata = ['revision' => null, 'dirty' => null];
        }
        $status = self::runGit($root, 'status', '--porcelain', '--untracked-files=normal');

        return $this->gitMetadata = [
            'revision' => $revision,
            'dirty' => $status === null ? null : trim($status) !== '',
        ];
    }

    private static function runGit(string $root, string ...$arguments): ?string
    {
        if (!function_exists('proc_open')) {
            return null;
        }
        $command = array_values(array_merge(['git', '-C', $root], $arguments));
        try {
            $process = @proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($process)) {
                return null;
            }
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            stream_get_contents($pipes[2]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);
            return $exitCode === 0 && is_string($stdout) ? trim($stdout) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<string, string|false|null> */
    private static function processEnvironment(): array
    {
        $sources = [
            is_array($_SERVER) ? $_SERVER : [],
            is_array($_ENV) ? $_ENV : [],
            getenv(),
        ];
        $environment = [];
        foreach ($sources as $source) {
            if (!is_array($source)) {
                continue;
            }
            foreach ($source as $key => $value) {
                if (is_string($key) && (is_string($value) || $value === false || $value === null)) {
                    $environment[$key] = $value;
                }
            }
        }

        return $environment;
    }
}
