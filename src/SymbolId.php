<?php

declare(strict_types=1);

namespace Indexr\Otel\Core;

/** Immutable, validated canonical identity shared by indexer and runtime spans. */
final class SymbolId
{
    public function __construct(
        public readonly string $kind,
        public readonly string $value,
    ) {
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
