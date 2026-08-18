<?php

namespace App\Services\Search;

use OpenSearch\Client;

class OpenSearchDriver implements SearchDriver
{
    public function __construct(
        private readonly Client $client,
        private readonly string $index,
        private readonly array $config,
    ) {
    }

    public function name(): string
    {
        return 'opensearch';
    }

    public function search(SearchQueryData $data): SearchResult
    {
        $body = [
            'from' => $data->offset(),
            'size' => $data->perPage,
            'track_total_hits' => true,
            'query' => $this->buildQuery($data),
            'aggs' => $this->buildAggregations(),
            'highlight' => [
                'fields' => [
                    'description' => new \stdClass,
                ],
                'pre_tags' => ['<mark>'],
                'post_tags' => ['</mark>'],
                'fragment_size' => 160,
                'number_of_fragments' => 1,
            ],
        ];

        if ($sort = $this->buildSort($data)) {
            $body['sort'] = $sort;
        }

        $response = $this->client->search([
            'index' => $this->index,
            'body' => $body,
        ]);

        $hits = [];
        foreach ($response['hits']['hits'] ?? [] as $hit) {
            $document = $hit['_source'];
            $document['score'] = round((float) ($hit['_score'] ?? 0), 4);
            $document['highlight'] = $hit['highlight']['description'][0] ?? null;
            $hits[] = $document;
        }

        return new SearchResult(
            hits: $hits,
            total: (int) ($response['hits']['total']['value'] ?? 0),
            maxScore: (float) ($response['hits']['max_score'] ?? 0),
            tookMs: (int) ($response['took'] ?? 0),
            driver: $this->name(),
            facets: $this->extractFacets($response['aggregations'] ?? []),
        );
    }

    /**
     * bool query:
     *   must   - the actual text match (fuzzy, multi field, boosted)
     *   should - soft ranking signals (quality, health, https, docs)
     *   filter - hard user filters, no scoring impact
     */
    private function buildQuery(SearchQueryData $data): array
    {
        $must = [];
        $should = [];

        $term = trim($data->query);

        if ($term === '') {
            $must[] = ['match_all' => new \stdClass];
        } else {
            $must[] = [
                'multi_match' => [
                    'query' => $term,
                    'fields' => $this->config['fields'],
                    'type' => 'best_fields',
                    // Typos: "wether forecats" must still find "Weather Forecast".
                    'fuzziness' => $this->config['fuzziness'],
                    'prefix_length' => 1,
                    'max_expansions' => 50,
                    'tie_breaker' => 0.3,
                    'operator' => 'or',
                    'minimum_should_match' => '2<70%',
                ],
            ];

            // Exact phrase on the name outranks a merely fuzzy match.
            $should[] = [
                'match_phrase' => [
                    'name' => ['query' => $term, 'boost' => 8],
                ],
            ];

            $should[] = [
                'match_phrase' => [
                    'description' => ['query' => $term, 'boost' => 2],
                ],
            ];
        }

        // Quality signals. Small boosts on purpose: they should break ties
        // between comparable matches, never drag an irrelevant API to the top.
        $should[] = ['term' => ['https' => ['value' => true, 'boost' => 0.6]]];
        $should[] = ['term' => ['has_openapi' => ['value' => true, 'boost' => 0.8]]];
        $should[] = ['term' => ['authentication_type' => ['value' => 'none', 'boost' => 0.5]]];
        $should[] = ['term' => ['health_status' => ['value' => 'healthy', 'boost' => 0.8]]];
        $should[] = [
            'function_score' => [
                'query' => ['match_all' => new \stdClass],
                'field_value_factor' => [
                    'field' => 'quality_score',
                    'factor' => 0.02,
                    'modifier' => 'sqrt',
                    'missing' => 0,
                ],
                'boost_mode' => 'replace',
            ],
        ];

        return [
            'bool' => [
                'must' => $must,
                'should' => $should,
                'filter' => $this->buildFilters($data),
                'must_not' => [
                    ['term' => ['status' => 'dead']],
                ],
            ],
        ];
    }

    private function buildFilters(SearchQueryData $data): array
    {
        $filters = [];

        if ($data->category) {
            $filters[] = ['term' => ['category.keyword' => $data->category]];
        }

        if ($data->authentication) {
            $filters[] = ['term' => ['authentication_type' => $data->authentication]];
        }

        if ($data->https !== null) {
            $filters[] = ['term' => ['https' => $data->https]];
        }

        if ($data->cors) {
            $filters[] = ['term' => ['cors' => $data->cors]];
        }

        if ($data->country) {
            $filters[] = ['term' => ['country' => $data->country]];
        }

        if ($data->hasOpenapi !== null) {
            $filters[] = ['term' => ['has_openapi' => $data->hasOpenapi]];
        }

        return $filters;
    }

    private function buildSort(SearchQueryData $data): array
    {
        return match ($data->sort) {
            'quality' => [['quality_score' => 'desc'], '_score'],
            'name' => [['name.keyword' => 'asc']],
            'updated' => [['updated_at' => 'desc']],
            default => [], // relevance = default _score ordering
        };
    }

    private function buildAggregations(): array
    {
        return [
            'categories' => ['terms' => ['field' => 'category.keyword', 'size' => 30]],
            'authentication' => ['terms' => ['field' => 'authentication_type', 'size' => 10]],
            'https' => ['terms' => ['field' => 'https', 'size' => 2]],
            'country' => ['terms' => ['field' => 'country', 'size' => 20]],
        ];
    }

    private function extractFacets(array $aggregations): array
    {
        $facets = [];

        foreach ($aggregations as $name => $agg) {
            $facets[$name] = array_map(
                fn (array $bucket) => [
                    'value' => $bucket['key_as_string'] ?? $bucket['key'],
                    'count' => $bucket['doc_count'],
                ],
                $agg['buckets'] ?? []
            );
        }

        return $facets;
    }
}
