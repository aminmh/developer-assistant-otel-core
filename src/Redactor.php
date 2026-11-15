<?php

declare(strict_types=1);

namespace Indexr\Otel\Core;

/** Removes sensitive values from telemetry attributes before span enrichment. */
final class Redactor
{
    private const SENSITIVE = '/(?:authorization|cookie|password|passwd|secret|token|api[_-]?key|session|body|parameter|exception\.message|exception\.stacktrace)/i';

    /**
     * @param array<string, mixed> $attributes
     * @return array<string, bool|int|float|string|array<int, bool|int|float|string>|null>
     */
    public function attributes(array $attributes): array
    {
        $safe = [];
        foreach ($attributes as $key => $value) {
            if (preg_match(self::SENSITIVE, (string) $key)) {
                continue;
            }
            if (is_string($value)) {
                $safe[$key] = $key === 'db.query.text'
                    ? $this->text($value)
                    : (preg_match('/\A(?:url\.full|url\.query|http\.url)\z/i', (string) $key) ? $this->url($value) : $value);
            } elseif (is_scalar($value) || $value === null) {
                $safe[$key] = $value;
            } elseif (is_array($value) && array_is_list($value) && $this->homogeneousScalars($value)) {
                $safe[$key] = $value;
            }
        }

        return $safe;
    }

    public function text(string $value): string
    {
        $value = preg_replace("/('(?:''|[^'])*'|\"(?:\"\"|[^\"])*\"|\\$\\d+|\\b\\d+(?:\\.\\d+)?\\b)/", '?', $value) ?? '';
        return substr($value, 0, 2048);
    }

    private function url(string $value): string
    {
        $value = preg_replace('/(\/\/)[^\/@]+@/', '$1[redacted]@', $value) ?? '';
        return preg_replace_callback('/([?&][^=&#]+)=([^&#]*)/', static fn (array $match): string => $match[1] . '?', $value) ?? '';
    }

    /** @param list<mixed> $values */
    private function homogeneousScalars(array $values): bool
    {
        if ($values === []) {
            return true;
        }
        $type = get_debug_type($values[0]);
        foreach ($values as $value) {
            if (!is_bool($value) && !is_int($value) && !is_float($value) && !is_string($value)) {
                return false;
            }
            if (get_debug_type($value) !== $type) {
                return false;
            }
        }
        return true;
    }
}
