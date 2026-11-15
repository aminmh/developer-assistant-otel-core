<?php

declare(strict_types=1);

namespace Indexr\Otel\Core;

use Throwable;

/** Builds bounded, privacy-safe exception context for cross-trace search. */
final class ExceptionContext
{
    private const MAX_MESSAGE_BYTES = 512;
    private const MAX_FRAMES = 5;

    /**
     * @param list<string> $applicationNamespaces
     * @return array{'indexer.exception.type': class-string<Throwable>, 'indexer.exception.normalized_message': string, 'indexer.exception.top_frames': list<string>}
     */
    public function attributes(Throwable $exception, array $applicationNamespaces): array
    {
        return [
            'indexer.exception.type' => $exception::class,
            'indexer.exception.normalized_message' => $this->normalizeMessage($exception->getMessage()),
            'indexer.exception.top_frames' => $this->applicationFrames($exception, $applicationNamespaces),
        ];
    }

    private function normalizeMessage(string $message): string
    {
        $message = preg_replace('/https?:\/\/\S+/i', '[url]', $message) ?? '';
        $message = preg_replace('~"[^"]*"~', '[value]', $message) ?? '';
        $quote = chr(39);
        $message = preg_replace('~' . $quote . '[^' . $quote . ']*' . $quote . '~', '[value]', $message) ?? '';
        $message = preg_replace('/\\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\\.[A-Z]{2,}\\b/i', '[email]', $message) ?? '';
        $message = preg_replace('/\b(?:\d{1,3}\.){3}\d{1,3}\b/', '[ip]', $message) ?? '';
        $message = preg_replace('/\b[0-9a-f]{8}-[0-9a-f-]{27,}\b/i', '[id]', $message) ?? '';
        $message = preg_replace('/\b\d+(?:\.\d+)?\b/', '?', $message) ?? '';
        $message = preg_replace('/\s+/', ' ', trim($message)) ?? '';

        return substr($message, 0, self::MAX_MESSAGE_BYTES);
    }

    /**
     * @param list<string> $applicationNamespaces
     * @return list<string>
     */
    private function applicationFrames(Throwable $exception, array $applicationNamespaces): array
    {
        $frames = [];
        $normalizer = new SymbolIdNormalizer();
        foreach ($exception->getTrace() as $frame) {
            $class = $frame['class'] ?? null;
            $function = $frame['function'];
            if (!is_string($class) || !$this->inApplication($class, $applicationNamespaces)) {
                continue;
            }
            try {
                $frames[] = (string) $normalizer->normalize([
                    'kind' => 'method',
                    'class' => $class,
                    'method' => $function,
                ]);
            } catch (Throwable) {
                continue;
            }
            if (count($frames) >= self::MAX_FRAMES) {
                break;
            }
        }

        return array_values(array_unique($frames));
    }

    /** @param list<string> $applicationNamespaces */
    private function inApplication(string $class, array $applicationNamespaces): bool
    {
        foreach ($applicationNamespaces as $namespace) {
            $namespace = trim($namespace, '\\');
            if ($namespace !== '' && ($class === $namespace || str_starts_with($class, $namespace . '\\'))) {
                return true;
            }
        }

        return false;
    }
}
