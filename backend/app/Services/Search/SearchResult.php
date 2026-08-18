<?php

namespace App\Services\Search;

class SearchResult
{
    /**
     * @param  array<int,array<string,mixed>>  $hits
     * @param  array<string,array<int,array{value:mixed,count:int}>>  $facets
     */
    public function __construct(
        public readonly array $hits,
        public readonly int $total,
        public readonly float $maxScore,
        public readonly int $tookMs,
        public readonly string $driver,
        public readonly array $facets = [],
    ) {
    }

    public static function empty(string $driver): self
    {
        return new self([], 0, 0.0, 0, $driver);
    }
}
