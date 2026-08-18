<?php

namespace App\Services\Search;

/**
 * Normalised search input. Built once in the controller and passed to
 * whichever driver serves the request.
 */
class SearchQueryData
{
    public function __construct(
        public readonly string $query = '',
        public readonly ?string $category = null,
        public readonly ?string $authentication = null,
        public readonly ?bool $https = null,
        public readonly ?string $cors = null,
        public readonly ?string $country = null,
        public readonly ?bool $hasOpenapi = null,
        public readonly int $page = 1,
        public readonly int $perPage = 20,
        public readonly string $sort = 'relevance', // relevance|quality|name|updated
    ) {
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    public function filters(): array
    {
        return array_filter([
            'category' => $this->category,
            'authentication' => $this->authentication,
            'https' => $this->https,
            'cors' => $this->cors,
            'country' => $this->country,
            'has_openapi' => $this->hasOpenapi,
        ], fn ($v) => $v !== null);
    }
}
