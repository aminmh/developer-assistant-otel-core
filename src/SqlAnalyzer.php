<?php

declare(strict_types=1);

namespace Indexr\Otel\Core;

/** Conservative tokenizer for SQL text; it never reads or emits bindings. */
final class SqlAnalyzer
{
    /** @param list<string> $tablePrefixes Laravel/Doctrine physical table prefixes */
    public function __construct(
        private readonly string $connection = 'default',
        private readonly array $tablePrefixes = [],
    ) {
    }

    public function analyze(string $sql): SqlQuery
    {
        $tokens = $this->tokens($sql);
        $normalized = [];
        $tables = [];
        $expectTable = false;
        $operation = null;

        for ($i = 0, $count = count($tokens); $i < $count; $i++) {
            $token = $tokens[$i];
            if ($token['type'] === 'literal') {
                $normalized[] = '?';
                continue;
            }
            $word = strtoupper($token['value']);
            if ($i === 0 && $token['type'] === 'word' && in_array($word, ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'REPLACE', 'CREATE', 'ALTER', 'DROP', 'TRUNCATE'], true)) {
                $operation = $word;
            }
            if ($token['type'] === 'word' && in_array($word, ['FROM', 'JOIN', 'UPDATE', 'INTO', 'REFERENCES'], true)) {
                $normalized[] = $word;
                $expectTable = true;
                continue;
            }
            if ($token['type'] === 'punctuation' && $token['value'] === '(') {
                $normalized[] = '(';
                // A SELECT immediately inside parentheses will identify its own tables.
                $expectTable = false;
                continue;
            }
            if ($expectTable && in_array($token['type'], ['word', 'identifier'], true)) {
                $parts = [$token['value']];
                $normalized[] = $token['value'];
                $expectTable = false;
                while (isset($tokens[$i + 1], $tokens[$i + 2])
                    && $tokens[$i + 1]['type'] === 'punctuation' && $tokens[$i + 1]['value'] === '.'
                    && in_array($tokens[$i + 2]['type'], ['word', 'identifier'], true)) {
                    $parts[] = $tokens[$i + 2]['value'];
                    $normalized[] = '.';
                    $normalized[] = $tokens[$i + 2]['value'];
                    $i += 2;
                }
                $table = $this->tableLeaf($parts);
                if ($table !== null) {
                    try {
                        $tables[] = (string) (new SymbolIdNormalizer())->normalize([
                            'kind' => 'table', 'connection' => $this->connection, 'name' => $table,
                        ]);
                    } catch (\Throwable) {
                        // Dynamic names and vendor-specific constructs are omitted safely.
                    }
                }
                continue;
            }
            $normalized[] = $token['value'];
        }

        return new SqlQuery($this->format($normalized), array_values(array_unique($tables)), $operation);
    }

    /** @param list<string> $parts */
    private function tableLeaf(array $parts): ?string
    {
        $table = end($parts);
        if (!is_string($table)) {
            return null;
        }
        foreach ($this->tablePrefixes as $prefix) {
            if ($prefix !== '' && str_starts_with($table, $prefix)) {
                $table = substr($table, strlen($prefix));
                break;
            }
        }

        return preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $table) ? $table : null;
    }

    /** @return list<array{type: string, value: string}> */
    private function tokens(string $sql): array
    {
        $tokens = [];
        $length = strlen($sql);
        for ($i = 0; $i < $length;) {
            $char = $sql[$i];
            if (ctype_space($char)) {
                $i++;
                continue;
            }
            if ($char === '-' && ($sql[$i + 1] ?? '') === '-') {
                $i = strpos($sql, "\n", $i) ?: $length;
                continue;
            }
            if ($char === '/' && ($sql[$i + 1] ?? '') === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $i = $end === false ? $length : $end + 2;
                continue;
            }
            if ($char === "'" || $char === '"' || $char === '`' || $char === '[') {
                $close = $char === '[' ? ']' : $char;
                $i++;
                $contentStart = $i;
                $identifier = $char !== "'";
                while ($i < $length) {
                    if ($sql[$i] === $close) {
                        if (($sql[$i + 1] ?? '') === $close) {
                            $i += 2;
                            continue;
                        }
                        $i++;
                        break;
                    }
                    if ($sql[$i] === '\\' && $char === "'" && $i + 1 < $length) {
                        $i += 2;
                    } else {
                        $i++;
                    }
                }
                $tokens[] = ['type' => $identifier ? 'identifier' : 'literal', 'value' => substr($sql, $contentStart, max(0, $i - $contentStart - 1))];
                continue;
            }
            if (ctype_alpha($char) || $char === '_') {
                $start = $i++;
                while ($i < $length && (ctype_alnum($sql[$i]) || $sql[$i] === '_')) {
                    $i++;
                }
                $tokens[] = ['type' => 'word', 'value' => substr($sql, $start, $i - $start)];
                continue;
            }
            if (ctype_digit($char) || $char === '?') {
                $start = $i++;
                while ($i < $length && (ctype_alnum($sql[$i]) || in_array($sql[$i], ['.', '_'], true))) {
                    $i++;
                }
                $tokens[] = ['type' => 'literal', 'value' => substr($sql, $start, $i - $start)];
                continue;
            }
            $tokens[] = ['type' => 'punctuation', 'value' => $char];
            $i++;
        }

        return $tokens;
    }

    /** @param list<string> $tokens */
    private function format(array $tokens): string
    {
        $sql = '';
        foreach ($tokens as $token) {
            if (in_array($token, [',', ')', '.', ';'], true)) {
                $sql = rtrim($sql) . $token;
            } elseif ($token === '(') {
                $sql = rtrim($sql) . ' (';
            } else {
                $sql .= ($sql === '' || str_ends_with($sql, ' (') || str_ends_with($sql, '.') ? '' : ' ') . $token;
            }
        }

        return trim($sql, " ;\t\n\r\0\x0B");
    }
}
