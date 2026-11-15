<?php

declare(strict_types=1);

namespace Indexr\Otel\Core;

use Throwable;

/** Bounded best-effort exporter for profiles sent after response/job completion. */
final class ProfileExportQueue
{
    /** @var list<array<string, mixed>> */
    private static array $profiles = [];
    private const MAX_PROFILE_BYTES = 262144;
    private const MAX_BATCH_BYTES = 1900000;

    /** @param array<string, mixed> $profile */
    public static function enqueue(array $profile): void
    {
        try {
            $encoded = json_encode($profile, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            if (strlen($encoded) > self::MAX_PROFILE_BYTES || count(self::$profiles) >= self::maxQueueSize()) {
                return;
            }
            self::$profiles[] = $profile;
        } catch (Throwable) {
        }
    }

    public static function flushAfterResponse(): void
    {
        $profiles = self::$profiles;
        self::$profiles = [];
        if ($profiles === []) {
            return;
        }
        $endpoint = self::environment('INDEXER_OTEL_PROFILE_ENDPOINT');
        $secret = self::environment('INDEXER_OTEL_PROFILE_TOKEN');
        if ($endpoint === null || $secret === null || strlen($secret) < 32
            || str_contains($secret, "\r") || str_contains($secret, "\n") || !self::validEndpoint($endpoint)) {
            return;
        }
        try {
            $groups = [];
            foreach ($profiles as $profile) {
                $identity = json_encode([$profile['project_id'] ?? null, $profile['revision_id'] ?? null]);
                $groups[$identity][] = $profile;
            }
            foreach ($groups as $group) {
                $batch = [];
                $batchBytes = 0;
                foreach ($group as $profile) {
                    $encoded = json_encode($profile, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
                    $nextBytes = strlen($encoded) + ($batch === [] ? 15 : 1);
                    if ($batch !== [] && (count($batch) >= 64 || $batchBytes + $nextBytes > self::MAX_BATCH_BYTES)) {
                        self::sendBatch($endpoint, $secret, $batch);
                        $batch = [];
                        $batchBytes = 0;
                        $nextBytes = strlen($encoded) + 15;
                    }
                    if ($nextBytes > self::MAX_BATCH_BYTES) {
                        continue;
                    }
                    $batch[] = $profile;
                    $batchBytes += $nextBytes;
                }
                if ($batch !== []) {
                    self::sendBatch($endpoint, $secret, $batch);
                }
            }
        } catch (Throwable) {
            // Profile delivery is best effort and must never affect application work.
        }
    }

    /** @param list<array<string, mixed>> $profiles */
    private static function sendBatch(string $endpoint, string $secret, array $profiles): void
    {
        try {
            $body = json_encode(['profiles' => $profiles], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            if (strlen($body) > self::MAX_BATCH_BYTES) {
                return;
            }
            $context = stream_context_create(['http' => [
                'method' => 'POST',
                'timeout' => 0.25,
                'ignore_errors' => true,
                'header' => "Content-Type: application/json\r\nX-Internal-Secret: " . $secret . "\r\n",
                'content' => $body,
            ]]);
            @file_get_contents($endpoint, false, $context);
        } catch (Throwable) {
            // Profile delivery is best effort and must never affect application work.
        }
    }

    public static function reset(): void
    {
        self::$profiles = [];
    }

    private static function maxQueueSize(): int
    {
        $value = self::environment('INDEXER_OTEL_PROFILE_QUEUE_SIZE');
        return $value !== null && ctype_digit($value) ? max(1, min(128, (int) $value)) : 16;
    }

    private static function validEndpoint(string $endpoint): bool
    {
        $parts = parse_url($endpoint);
        return is_array($parts) && in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            && isset($parts['host']) && !isset($parts['user']) && !isset($parts['pass'])
            && !isset($parts['fragment']) && !isset($parts['query']);
    }

    private static function environment(string $name): ?string
    {
        $value = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);
        return is_string($value) && $value !== '' ? $value : null;
    }
}
