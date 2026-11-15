<?php

declare(strict_types=1);

namespace Indexr\Otel\Core;

use Throwable;

/**
 * Keeps the method hook manifest in step with the code without a manual export.
 *
 * Called after the response is sent. At most once per interval it compares the class files under the
 * hook namespaces with the fingerprint stored in the manifest and rewrites the manifest when they
 * differ; the next request loads the new hooks. Between checks a request costs one stat() call.
 */
final class MethodHookManifestRefresher
{
    public const DEFAULT_INTERVAL_SECONDS = 30;

    /**
     * @param list<string> $namespaceGlobs
     * @param callable(): list<string> $namedClasses classes the framework names (controllers, services)
     * @return int|null the new method count when the manifest was rewritten, otherwise null
     */
    public static function refreshIfStale(string $path, array $namespaceGlobs, callable $namedClasses, int $intervalSeconds = self::DEFAULT_INTERVAL_SECONDS, ?int $now = null): ?int
    {
        if ($intervalSeconds <= 0 || $path === '' || $namespaceGlobs === []) {
            return null;
        }
        $now ??= time();
        $stamp = $path . '.checked';
        try {
            $checked = @filemtime($stamp);
            if ($checked !== false && $now - $checked < $intervalSeconds) {
                return null;
            }
            // Mark the check first so concurrent workers do not all scan; a stamp another user owns is replaced.
            $directory = dirname($path);
            if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
                return null;
            }
            if (!@touch($stamp, $now)) {
                @unlink($stamp);
                if (!@touch($stamp, $now)) {
                    return null;
                }
            }
            $fingerprint = MethodHookManifest::fingerprint($namespaceGlobs);
            if (self::storedFingerprint($path) === $fingerprint) {
                return null;
            }
            return MethodHookManifest::write($path, $namedClasses(), $namespaceGlobs, $fingerprint);
        } catch (Throwable) {
            return null;
        }
    }

    private static function storedFingerprint(string $path): ?string
    {
        if (!is_file($path)) {
            return null;
        }
        try {
            $document = json_decode((string) file_get_contents($path), true, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }
        $fingerprint = is_array($document) ? ($document['source_fingerprint'] ?? null) : null;
        return is_string($fingerprint) ? $fingerprint : null;
    }
}
