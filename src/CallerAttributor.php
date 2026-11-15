<?php

declare(strict_types=1);

namespace Indexr\Otel\Core;

/** Resolves in-app stack frames only for recorded spans. */
final class CallerAttributor
{
    /** Measured on Pizza Builder: nested Eloquent eager loads reach the first App frame ~30 frames below the hook. */
    public const DEFAULT_DEPTH = 40;
    private const MIN_DEPTH = 5;
    private const MAX_DEPTH = 200;
    /** Matches PHP 8.4+ closure frame names: `{closure:<scope>:<definition line>}`. */
    private const CLOSURE_NAME = '/\A\{closure:(.+):(\d+)\}\z/sD';
    private const MAX_CLOSURE_NESTING = 16;

    /** @var array<string, string|false> Defining file per class or function scope, cached per process. */
    private static array $scopeFiles = [];

    private readonly int $depth;
    /** @var list<string> */
    private readonly array $excludedPaths;

    /**
     * @param list<string> $applicationNamespaces
     * @param list<string> $excludedPaths Repository-relative directories never attributed (Composer vendor-dir by default).
     */
    public function __construct(
        private readonly array $applicationNamespaces,
        private readonly string $projectRoot,
        private readonly SymbolIdNormalizer $normalizer = new SymbolIdNormalizer(),
        ?int $depth = null,
        array $excludedPaths = ['vendor'],
    ) {
        $this->depth = self::boundedDepth($depth ?? self::environmentDepth());
        $this->excludedPaths = array_values(array_filter(
            array_map(static fn (string $path): string => trim(str_replace('\\', '/', $path), '/'), $excludedPaths),
            static fn (string $path): bool => $path !== '',
        ));
    }

    /** @return array{symbol_id: ?string, caller_symbol_id: ?string} */
    public function attribute(bool $recorded): array
    {
        if (!$recorded || $this->applicationNamespaces === []) {
            return ['symbol_id' => null, 'caller_symbol_id' => null];
        }

        try {
            return $this->attributeFrames(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $this->depth));
        } catch (\Throwable) {
            return ['symbol_id' => null, 'caller_symbol_id' => null];
        }
    }

    /**
     * Picks the innermost two distinct application symbols from backtrace frames (innermost first).
     *
     * @param list<array<string, mixed>> $frames
     * @return array{symbol_id: ?string, caller_symbol_id: ?string}
     */
    public function attributeFrames(array $frames): array
    {
        $symbols = [];
        foreach ($frames as $frame) {
            try {
                $symbol = $this->frameSymbol($frame);
            } catch (\Throwable) {
                // One unrecognised frame must not discard the frames around it.
                $symbol = null;
            }
            if ($symbol !== null && end($symbols) !== $symbol) {
                $symbols[] = $symbol;
            }
            if (count($symbols) === 2) {
                break;
            }
        }

        return ['symbol_id' => $symbols[0] ?? null, 'caller_symbol_id' => $symbols[1] ?? null];
    }

    /** @param array<string, mixed> $frame */
    private function frameSymbol(array $frame): ?string
    {
        $function = $frame['function'] ?? null;
        if (!is_string($function) || $function === '') {
            return null;
        }
        if (str_starts_with($function, '{closure')) {
            return $this->closureSymbol($function);
        }
        $class = $frame['class'] ?? null;
        if (is_string($class) && $this->isApplicationClass($class)) {
            return (string) $this->normalizer->normalize(['kind' => 'method', 'class' => $class, 'method' => $function]);
        }
        return null;
    }

    private function isApplicationClass(string $class): bool
    {
        foreach ($this->applicationNamespaces as $namespace) {
            if ($namespace !== '' && str_starts_with(ltrim($class, '\\'), trim($namespace, '\\') . '\\')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Builds `closure@<path>:<definition line>` from a PHP 8.4+ frame name.
     *
     * Before PHP 8.4 a closure frame is only `{closure}`, and the backtrace exposes the call site rather
     * than the definition, so no identifier matching the indexed definition can be derived; the frame is
     * skipped and attribution continues with the enclosing named method.
     */
    private function closureSymbol(string $function): ?string
    {
        if (!preg_match(self::CLOSURE_NAME, $function, $match)) {
            return null;
        }
        $line = (int) $match[2];
        $file = self::scopeFile($match[1], 0);
        if ($line < 1 || $file === null) {
            return null;
        }
        $relative = $this->relativeApplicationPath($file);
        if ($relative === null) {
            return null;
        }
        return (string) $this->normalizer->normalize(['kind' => 'closure', 'path' => $relative, 'line' => $line]);
    }

    /** Resolves the file that defines a closure scope: a parent closure, `Class::method()`, `function()`, or a path. */
    private static function scopeFile(string $scope, int $nesting): ?string
    {
        if ($nesting > self::MAX_CLOSURE_NESTING) {
            return null;
        }
        if (str_starts_with($scope, '{closure')) {
            return preg_match(self::CLOSURE_NAME, $scope, $parent) ? self::scopeFile($parent[1], $nesting + 1) : null;
        }
        if (!str_ends_with($scope, '()')) {
            return $scope;
        }
        if (array_key_exists($scope, self::$scopeFiles)) {
            $cached = self::$scopeFiles[$scope];
            return $cached === false ? null : $cached;
        }
        $name = substr($scope, 0, -2);
        $file = false;
        try {
            if (str_contains($name, '::')) {
                $class = explode('::', $name, 2)[0];
                if (class_exists($class, false) || interface_exists($class, false) || trait_exists($class, false)
                    || (function_exists('enum_exists') && enum_exists($class, false))) {
                    $file = (new \ReflectionClass($class))->getFileName();
                }
            } elseif (function_exists($name)) {
                $file = (new \ReflectionFunction($name))->getFileName();
            }
        } catch (\Throwable) {
            $file = false;
        }
        if (count(self::$scopeFiles) >= 4096) {
            self::$scopeFiles = [];
        }
        self::$scopeFiles[$scope] = is_string($file) ? $file : false;
        return is_string($file) ? $file : null;
    }

    private function relativeApplicationPath(string $file): ?string
    {
        $root = realpath($this->projectRoot);
        $absolute = realpath($file);
        if ($root === false || $absolute === false) {
            return null;
        }
        $prefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (!str_starts_with($absolute, $prefix)) {
            return null;
        }
        $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($absolute, strlen($prefix)));
        foreach ($this->excludedPaths as $excluded) {
            if ($relative === $excluded || str_starts_with($relative, $excluded . '/')) {
                return null;
            }
        }
        return $relative;
    }

    private static function environmentDepth(): int
    {
        $value = $_SERVER['INDEXER_OTEL_BACKTRACE_DEPTH'] ?? $_ENV['INDEXER_OTEL_BACKTRACE_DEPTH'] ?? getenv('INDEXER_OTEL_BACKTRACE_DEPTH');
        return is_string($value) && ctype_digit($value) ? (int) $value : self::DEFAULT_DEPTH;
    }

    private static function boundedDepth(int $depth): int
    {
        return max(self::MIN_DEPTH, min(self::MAX_DEPTH, $depth));
    }
}
