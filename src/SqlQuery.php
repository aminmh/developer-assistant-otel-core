<?php

declare(strict_types=1);

namespace Indexr\Otel\Core;

/** Privacy-safe SQL summary and canonical table IDs. */
final class SqlQuery
{
    public function __construct(
        public readonly string $normalized,
        /** @var list<string> */
        public readonly array $tableIds,
        public readonly ?string $operation = null,
    ) {
    }
}
