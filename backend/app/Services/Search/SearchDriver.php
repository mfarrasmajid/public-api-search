<?php

namespace App\Services\Search;

interface SearchDriver
{
    public function search(SearchQueryData $data): SearchResult;

    public function name(): string;
}
