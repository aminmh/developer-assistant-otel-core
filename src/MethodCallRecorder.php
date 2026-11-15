<?php

declare(strict_types=1);

namespace Indexr\Otel\Core;

/** Bounded per-request direct-call counts collected without adding a span per call. */
final class MethodCallRecorder
{
    /** @var array{stack: list<string>, calls: array<string, int>, overflow: int, dropped: int} */
    private static array $mainState = ['stack' => [], 'calls' => [], 'overflow' => 0, 'dropped' => 0];
    private static int $lastDropped = 0;
    /** @var \WeakMap<object, mixed>|null */
    private static ?\WeakMap $fiberStates = null;

    public static function enter(string $symbolId): void
    {
        $state = self::state();
        if ($state['overflow'] > 0 || count($state['stack']) >= 128) {
            ++$state['overflow'];
            ++$state['dropped'];
            self::save($state);
            return;
        }
        $caller = $state['stack'] === [] ? null : $state['stack'][array_key_last($state['stack'])];
        if (is_string($caller) && $caller !== $symbolId) {
            $key = $caller . "\n" . $symbolId;
            if (isset($state['calls'][$key]) || count($state['calls']) < 256) {
                $state['calls'][$key] = ($state['calls'][$key] ?? 0) + 1;
            } else {
                ++$state['dropped'];
            }
        }
        $state['stack'][] = $symbolId;
        self::save($state);
    }

    public static function leave(string $symbolId): void
    {
        $state = self::state();
        if ($state['overflow'] > 0) {
            --$state['overflow'];
            self::save($state);
            return;
        }
        for ($index = count($state['stack']) - 1; $index >= 0; --$index) {
            if ($state['stack'][$index] === $symbolId) {
                $state['stack'] = array_slice($state['stack'], 0, $index);
                self::save($state);
                return;
            }
        }
    }

    /** @return list<array{caller_symbol_id: string, symbol_id: string, calls: int}> */
    public static function drain(): array
    {
        $state = self::state();
        $rows = [];
        foreach ($state['calls'] as $edge => $calls) {
            [$caller, $callee] = explode("\n", $edge, 2);
            $rows[] = ['caller_symbol_id' => $caller, 'symbol_id' => $callee, 'calls' => $calls];
        }
        usort($rows, static fn (array $left, array $right): int => $right['calls'] <=> $left['calls']);
        self::reset();
        self::$lastDropped = $state['dropped'];
        return array_slice($rows, 0, 256);
    }

    /** Calls the last drain() could not record: beyond the 256-edge cap or the 128-frame depth cap. */
    public static function lastDroppedCalls(): int
    {
        return self::$lastDropped;
    }

    public static function reset(): void
    {
        self::save(['stack' => [], 'calls' => [], 'overflow' => 0, 'dropped' => 0]);
    }

    /** @return array{stack: list<string>, calls: array<string, int>, overflow: int, dropped: int} */
    private static function state(): array
    {
        $fiber = class_exists(\Fiber::class) ? \Fiber::getCurrent() : null;
        if ($fiber === null) {
            return self::$mainState;
        }
        self::$fiberStates ??= new \WeakMap();
        $state = self::$fiberStates[$fiber] ?? null;
        if (!is_array($state) || !is_array($state['stack'] ?? null)
            || !is_array($state['calls'] ?? null) || !is_int($state['overflow'] ?? null)) {
            return ['stack' => [], 'calls' => [], 'overflow' => 0, 'dropped' => 0];
        }
        $stack = array_values(array_filter($state['stack'], 'is_string'));
        $calls = [];
        foreach ($state['calls'] as $edge => $count) {
            if (is_string($edge) && is_int($count) && $count > 0) {
                $calls[$edge] = $count;
            }
        }
        $dropped = is_int($state['dropped'] ?? null) ? max(0, $state['dropped']) : 0;
        return ['stack' => $stack, 'calls' => $calls, 'overflow' => max(0, $state['overflow']), 'dropped' => $dropped];
    }

    /** @param array{stack: list<string>, calls: array<string, int>, overflow: int, dropped: int} $state */
    private static function save(array $state): void
    {
        $fiber = class_exists(\Fiber::class) ? \Fiber::getCurrent() : null;
        if ($fiber === null) {
            self::$mainState = $state;
            return;
        }
        self::$fiberStates ??= new \WeakMap();
        self::$fiberStates[$fiber] = [
            'stack' => $state['stack'],
            'calls' => $state['calls'],
            'overflow' => $state['overflow'],
            'dropped' => $state['dropped'],
        ];
    }
}
