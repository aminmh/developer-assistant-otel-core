<?php

declare(strict_types=1);

namespace Indexr\Otel\Core;

use Composer\Autoload\ClassLoader;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

/**
 * Builds a bounded method-hook manifest.
 *
 * Classes the framework names (route controllers, registered services) come first; every other
 * class Composer can autoload under the hook namespaces follows (actions, resources, models...), so
 * code that a controller calls is counted too. The list stops at MAX_METHODS.
 */
final class MethodHookManifest
{
    public const MAX_METHODS = 500;
    private const MAX_DISCOVERED_FILES = 5000;

    /**
     * @param list<string> $classes Classes named by the framework; they keep priority under the limit.
     * @param list<string> $namespaceGlobs
     * @param list<string>|null $discovered Classes under the namespaces; null discovers them through Composer.
     * @return list<string>
     */
    public static function generate(array $classes, array $namespaceGlobs, int $limit = self::MAX_METHODS, ?array $discovered = null): array
    {
        $limit = max(1, min(self::MAX_METHODS, $limit));
        $named = self::sortedUnique($classes);
        $discovered = self::sortedUnique($discovered ?? self::discover($namespaceGlobs));
        $methods = [];
        foreach (array_values(array_unique([...$named, ...$discovered])) as $class) {
            if (!self::matches($class, $namespaceGlobs)) {
                continue;
            }
            try {
                if (!class_exists($class)) {
                    continue;
                }
                $reflection = new ReflectionClass($class);
                if ($reflection->isInterface() || $reflection->isTrait() || (method_exists($reflection, 'isEnum') && $reflection->isEnum())) {
                    continue;
                }
                $frameworkTraitMethods = self::frameworkTraitMethods($reflection, $namespaceGlobs);
                foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                    if (isset($frameworkTraitMethods[strtolower($method->getName())]) || $method->isConstructor() || $method->isDestructor() || $method->isStatic() || $method->isAbstract()
                        || (str_starts_with($method->getName(), '__') && $method->getName() !== '__invoke')
                        || $method->getDeclaringClass()->getName() !== $class
                        || self::isFrameworkAccessorOverride($reflection, $method->getName(), $namespaceGlobs)) {
                        continue;
                    }
                    $methods[] = $class . '::' . $method->getName();
                    if (count($methods) >= $limit) {
                        break 2;
                    }
                }
            } catch (Throwable) {
                continue;
            }
        }
        sort($methods);
        return array_values(array_unique($methods));
    }

    /**
     * Class names Composer can autoload under the namespaces: its class map plus a scan of the
     * matching PSR-4 directories. Directories under vendor are skipped.
     *
     * @param list<string> $namespaceGlobs
     * @return list<string>
     */
    public static function discover(array $namespaceGlobs): array
    {
        return self::sortedUnique(array_keys(self::discoverFiles($namespaceGlobs)));
    }

    /**
     * Changes when a class file under the namespaces is added, removed or edited (path, size, mtime);
     * stored in the manifest so a refresh can tell whether it is out of date.
     *
     * @param list<string> $namespaceGlobs
     */
    public static function fingerprint(array $namespaceGlobs): string
    {
        $entries = [];
        foreach (self::discoverFiles($namespaceGlobs) as $class => $file) {
            $stat = @stat($file);
            $entries[] = $class . '|' . ($stat === false ? '-' : $stat['size'] . '|' . $stat['mtime']);
        }
        sort($entries);
        return sha1(implode("\n", $entries) . "\n" . implode(',', $namespaceGlobs));
    }

    /**
     * Generates the manifest and replaces $path atomically, so a request never reads a partial file.
     *
     * @param list<string> $classes
     * @param list<string> $namespaceGlobs
     * @return int|null the number of methods, or null when the file could not be written
     */
    public static function write(string $path, array $classes, array $namespaceGlobs, ?string $fingerprint = null): ?int
    {
        $methods = self::generate($classes, $namespaceGlobs);
        $json = json_encode([
            'schema_version' => 1,
            'source_fingerprint' => $fingerprint ?? self::fingerprint($namespaceGlobs),
            'methods' => $methods,
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            return null;
        }
        $temporary = $path . '.' . getmypid() . '.tmp';
        if (@file_put_contents($temporary, $json, LOCK_EX) === false) {
            return null;
        }
        @chmod($temporary, 0664);
        if (!@rename($temporary, $path)) {
            @unlink($temporary);
            return null;
        }
        return count($methods);
    }

    /**
     * @param list<string> $namespaceGlobs
     * @return array<string, string> class name => file
     */
    private static function discoverFiles(array $namespaceGlobs): array
    {
        $prefixes = array_values(array_filter(array_map(static fn (string $glob): string => rtrim(trim($glob), '*\\'), $namespaceGlobs)));
        if ($prefixes === [] || !class_exists(ClassLoader::class, false) || !method_exists(ClassLoader::class, 'getRegisteredLoaders')) {
            return [];
        }
        $classes = [];
        $files = 0;
        foreach (ClassLoader::getRegisteredLoaders() as $loader) {
            foreach ($loader->getClassMap() as $class => $file) {
                if (self::matches($class, $prefixes) && !str_contains(str_replace('\\', '/', (string) $file), '/vendor/')) {
                    $classes[$class] = (string) $file;
                }
            }
            foreach ($loader->getPrefixesPsr4() as $psr4Prefix => $directories) {
                $psr4Prefix = rtrim($psr4Prefix, '\\');
                $overlaps = false;
                foreach ($prefixes as $prefix) {
                    // The PSR-4 root lies inside the namespace, or the namespace inside the root.
                    $overlaps = $overlaps || self::within($psr4Prefix, $prefix) || self::within($prefix, $psr4Prefix);
                }
                if (!$overlaps) {
                    continue;
                }
                foreach ($directories as $directory) {
                    $root = realpath((string) $directory);
                    foreach (self::phpFiles((string) $directory, $files) as $relative) {
                        $class = $psr4Prefix . '\\' . str_replace('/', '\\', substr($relative, 0, -4));
                        if ($root !== false && self::matches($class, $prefixes)) {
                            $classes[$class] ??= $root . '/' . $relative;
                        }
                    }
                }
            }
        }
        ksort($classes);
        return $classes;
    }

    /** @return list<string> paths relative to $directory, with forward slashes */
    private static function phpFiles(string $directory, int &$files): array
    {
        $root = realpath($directory);
        if ($root === false || !is_dir($root)) {
            return [];
        }
        $root = str_replace('\\', '/', $root);
        if (str_contains($root . '/', '/vendor/')) {
            return [];
        }
        $result = [];
        try {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($files >= self::MAX_DISCOVERED_FILES) {
                    break;
                }
                $relative = substr(str_replace('\\', '/', $file->getPathname()), strlen($root) + 1);
                if (!$file->isFile()
                    || !preg_match('#\\A(?:[A-Za-z_][A-Za-z0-9_]*/)*[A-Za-z_][A-Za-z0-9_]*\\.php\\z#', $relative)) {
                    continue;
                }
                ++$files;
                $result[] = $relative;
            }
        } catch (Throwable) {
        }
        return $result;
    }

    /**
     * Overrides such as Model::getIncrementing() or getKeyType() are called by framework internals
     * hundreds of times per request: hooking them adds overhead and drowns the call edges that matter.
     * Overridden entry points (toArray, handle, share...) and the app's own accessors stay hooked.
     *
     * @param ReflectionClass<object> $class
     * @param list<string> $namespaceGlobs
     */
    private static function isFrameworkAccessorOverride(ReflectionClass $class, string $method, array $namespaceGlobs): bool
    {
        if (!preg_match('/\\A(?:get|set|is|has|uses|should)[A-Z]/', $method)) {
            return false;
        }
        for ($parent = $class->getParentClass(); $parent !== false; $parent = $parent->getParentClass()) {
            if ($parent->hasMethod($method)
                && !self::matches($parent->getMethod($method)->getDeclaringClass()->getName(), $namespaceGlobs)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Methods a class takes from framework traits (SoftDeletes, HasFactory...). PHP reports them as
     * declared by the class itself, but they are framework plumbing, not application code.
     *
     * @param ReflectionClass<object> $class
     * @param list<string> $namespaceGlobs
     * @return array<string, true> lower-cased method names
     */
    private static function frameworkTraitMethods(ReflectionClass $class, array $namespaceGlobs): array
    {
        $names = [];
        $pending = array_values($class->getTraits());
        $seen = [];
        while ($pending !== []) {
            $trait = array_pop($pending);
            if (isset($seen[$trait->getName()])) {
                continue;
            }
            $seen[$trait->getName()] = true;
            foreach ($trait->getTraits() as $nested) {
                $pending[] = $nested;
            }
            if (self::matches($trait->getName(), $namespaceGlobs)) {
                continue;
            }
            foreach ($trait->getMethods() as $method) {
                $names[strtolower($method->getName())] = true;
            }
        }
        return $names;
    }

    private static function within(string $name, string $namespace): bool
    {
        return $namespace !== '' && ($name === $namespace || str_starts_with($name, $namespace . '\\'));
    }

    /** @param list<string> $namespaceGlobs */
    private static function matches(string $class, array $namespaceGlobs): bool
    {
        foreach ($namespaceGlobs as $glob) {
            if (self::within($class, rtrim(trim($glob), '*\\'))) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<mixed> $classes
     * @return list<string>
     */
    private static function sortedUnique(array $classes): array
    {
        $classes = array_values(array_unique(array_filter($classes, 'is_string')));
        sort($classes);
        return $classes;
    }
}
