<?php

declare(strict_types=1);

namespace Indexr\Otel\Core;

use Throwable;

/** Registers a bounded static hook manifest when ext-opentelemetry is available. */
final class MethodHookRegistry
{
    private static bool $registered = false;
    /** @var array<string, true> */
    private static array $hooked = [];

    /** Whether the manifest hooked this method, so its own pre/post callbacks track it on the call stack. */
    public static function isHooked(string $symbolId): bool
    {
        return isset(self::$hooked[$symbolId]);
    }

    /** A relative path is taken from $baseDir (the project root), so .env can name storage/... */
    public static function resolvePath(?string $path, ?string $baseDir): ?string
    {
        if (!is_string($path) || trim($path) === '') {
            return null;
        }
        $path = trim($path);
        $absolute = str_starts_with($path, '/') || str_starts_with($path, '\\') || preg_match('/\\A[A-Za-z]:[\\\\\\/]/', $path) === 1;
        if ($absolute || !is_string($baseDir) || trim($baseDir) === '') {
            return $path;
        }
        return rtrim($baseDir, '/\\') . '/' . $path;
    }

    public static function registerFromFile(?string $path, bool $enabled = true, ?string $baseDir = null): int
    {
        $path = self::resolvePath($path, $baseDir);
        if (self::$registered || !$enabled || !is_string($path) || trim($path) === '' || !is_file($path)
            || !function_exists('OpenTelemetry\\Instrumentation\\hook')) {
            return 0;
        }
        self::$registered = true;
        try {
            $document = json_decode((string) file_get_contents($path), true, 8, JSON_THROW_ON_ERROR);
            $methods = is_array($document) ? ($document['methods'] ?? null) : null;
            if (!is_array($methods) || ($document['schema_version'] ?? null) !== 1) {
                return 0;
            }
            $registered = 0;
            $methods = array_values(array_unique(array_filter($methods, 'is_string')));
            foreach (array_slice($methods, 0, MethodHookManifest::MAX_METHODS) as $symbolId) {
                if (!preg_match('/\\A[A-Za-z_\\\\][A-Za-z0-9_\\\\]*::[A-Za-z_][A-Za-z0-9_]*\\z/D', $symbolId)) {
                    continue;
                }
                [$class, $method] = explode('::', $symbolId, 2);
                try {
                    \OpenTelemetry\Instrumentation\hook(
                        $class,
                        $method,
                        pre: static function (mixed $object, array $params, string $class, string $function, ?string $filename, ?int $line) use ($symbolId): void {
                            MethodCallRecorder::enter($symbolId);
                        },
                        post: static function (mixed $object, array $params, mixed $returnValue, ?Throwable $exception) use ($symbolId): void {
                            MethodCallRecorder::leave($symbolId);
                        },
                    );
                    self::$hooked[$symbolId] = true;
                    ++$registered;
                } catch (Throwable) {
                    // One unavailable method must not disable the rest of the manifest.
                }
            }
            return $registered;
        } catch (Throwable) {
            return 0;
        }
    }
}
