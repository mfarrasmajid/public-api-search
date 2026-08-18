<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SearchRequest;
use App\Services\Search\SearchService;
use Illuminate\Http\JsonResponse;

class SearchController extends Controller
{
    public function __construct(private readonly SearchService $search)
    {
    }

    /**
     * GET /api/search?q=weather+indonesia
     */
    public function __invoke(SearchRequest $request): JsonResponse
    {
        $query = $request->toSearchQuery();
        $result = $this->search->search($query);

        return response()->json([
            'query' => $query->query,
            'total' => $result->total,
            'page' => $query->page,
            'per_page' => $query->perPage,
            'took_ms' => $result->tookMs,
            'driver' => $result->driver,
            'filters' => $query->filters(),
            'facets' => $result->facets,
            'results' => array_map($this->presentHit(...), $result->hits),
        ]);
    }

    /**
     * Search hits come straight from the index, so they are shaped here
     * rather than through an Eloquent resource.
     */
    private function presentHit(array $hit): array
    {
        return [
            'name' => $hit['name'] ?? null,
            'slug' => $hit['slug'] ?? null,
            'score' => $hit['score'] ?? null,
            'description' => $hit['description'] ?? null,
            'highlight' => $hit['highlight'] ?? null,
            'category' => $hit['category'] ?? null,
            'provider' => $hit['provider'] ?? null,
            'tags' => $hit['tags'] ?? [],
            'authentication' => $hit['authentication_type'] ?? 'unknown',
            'https' => (bool) ($hit['https'] ?? false),
            'cors' => $hit['cors'] ?? 'unknown',
            'country' => $hit['country'] ?? null,
            'documentation_url' => $hit['documentation_url'] ?? null,
            'base_url' => $hit['base_url'] ?? null,
            'has_openapi' => (bool) ($hit['has_openapi'] ?? false),
            'quality_score' => (int) ($hit['quality_score'] ?? 0),
            'health_status' => $hit['health_status'] ?? 'unknown',
            'response_time_ms' => $hit['response_time_ms'] ?? null,
        ];
    }
}
