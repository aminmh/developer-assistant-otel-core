<?php

declare(strict_types=1);

namespace Indexr\Otel\Core;

use InvalidArgumentException;

/** The single serialization boundary for canonical Indexr symbol identifiers. */
final class SymbolIdNormalizer
{
    private const IDENTIFIER = '[A-Za-z_][A-Za-z0-9_]*';

    /** @param array<string, mixed> $input */
    public function normalize(array $input): SymbolId
    {
        $kind = self::field($input, 'kind');
        $value = match ($kind) {
            'method' => self::qualified(self::field($input, 'class')) . '::' . self::identifier(self::field($input, 'method')),
            'function' => '\\' . self::qualified(self::field($input, 'name')),
            'closure' => self::closure($input),
            'controller' => self::controller(self::field($input, 'controller')),
            'route' => self::route($input),
            'table' => 'table:' . self::identifier(self::field($input, 'connection')) . '.' . self::identifier(self::field($input, 'name')),
            default => throw new InvalidArgumentException('Unsupported symbol kind'),
        };

        return new SymbolId($kind === 'controller' ? 'method' : $kind, $value);
    }

    /** @param array<string, mixed> $input */
    private static function field(array $input, string $key): string
    {
        if (!isset($input[$key]) || !is_string($input[$key]) || trim($input[$key]) === '') {
            throw new InvalidArgumentException('Missing or invalid ' . $key);
        }

        return trim($input[$key]);
    }

    private static function identifier(string $value): string
    {
        if (!preg_match('/\A' . self::IDENTIFIER . '\z/D', $value)) {
            throw new InvalidArgumentException('Invalid identifier');
        }

        return $value;
    }

    private static function qualified(string $value): string
    {
        if (str_starts_with($value, '\\')) {
            $value = substr($value, 1);
        }
        foreach (explode('\\', $value) as $segment) {
            self::identifier($segment);
        }

        return $value;
    }

    /** @param array<string, mixed> $input */
    private static function closure(array $input): string
    {
        $path = str_replace('\\', '/', self::field($input, 'path'));
        while (str_starts_with($path, './')) {
            $path = substr($path, 2);
        }
        if ($path === '' || str_starts_with($path, '/') || preg_match('/[:\x00-\x1f\x7f]/', $path)) {
            throw new InvalidArgumentException('Invalid repository-relative path');
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new InvalidArgumentException('Invalid repository-relative path');
            }
        }
        $line = $input['line'] ?? null;
        if (!is_int($line) || $line < 1) {
            throw new InvalidArgumentException('Closure line must be a positive integer');
        }

        return 'closure@' . $path . ':' . $line;
    }

    private static function controller(string $value): string
    {
        $parts = preg_split('/@|::/', $value);
        if (!is_array($parts) || count($parts) > 2) {
            throw new InvalidArgumentException('Invalid controller reference');
        }
        $class = trim($parts[0]);
        if (!str_contains($class, '\\')) {
            throw new InvalidArgumentException('Unresolved controller service identifier');
        }

        return self::qualified($class) . '::' . self::identifier(isset($parts[1]) ? trim($parts[1]) : '__invoke');
    }

    /** @param array<string, mixed> $input */
    private static function route(array $input): string
    {
        $method = self::field($input, 'method');
        if (!preg_match('/\A[A-Za-z]+\z/D', $method)) {
            throw new InvalidArgumentException('Route requires one HTTP method');
        }
        $template = self::field($input, 'template');
        $withoutParameters = preg_replace('/\{' . self::IDENTIFIER . '\??\}/', 'parameter', $template);
        if (!is_string($withoutParameters) || !str_starts_with($template, '/')
            || preg_match('/[\s\x00-\x1f\x7f?#\\\\]/', $withoutParameters)
            || str_contains($withoutParameters, '{') || str_contains($withoutParameters, '}')
            || str_contains($template, '//')) {
            throw new InvalidArgumentException('Invalid route template');
        }
        foreach (explode('/', $withoutParameters) as $segment) {
            if ($segment === '.' || $segment === '..') {
                throw new InvalidArgumentException('Invalid route template');
            }
        }

        return 'route:' . strtoupper($method) . ' ' . $template;
    }
}
