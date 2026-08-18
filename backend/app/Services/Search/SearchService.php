<?php

namespace App\Services\Search;

use App\Models\SearchQuery;
use Illuminate\Support\Facades\Log;

/**
 * Entry point for every search request.
 *
 * Responsibilities kept here on purpose (the controller stays thin):
 *   - pick the driver, fall back when OpenSearch misbehaves
 *   - record query telemetry
 */
class SearchService
{
    public function __construct(
        private readonly SearchDriver $primary,
        private readonly SearchDriver $fallback,
        private readonly bool $fallbackEnabled = true,
    ) {
    }

    public function search(SearchQueryData $data, bool $recordTelemetry = true): SearchResult
    {
        try {
            $result = $this->primary->search($data);
        } catch (\Throwable $e) {
            if (! $this->fallbackEnabled) {
                throw $e;
            }

            Log::warning('Primary search driver failed, falling back to database', [
                'driver' => $this->primary->name(),
                'error' => $e->getMessage(),
            ]);

            $result = $this->fallback->search($data);
        }

        if ($recordTelemetry) {
            $this->record($data, $result);
        }

        return $result;
    }

    private function record(SearchQueryData $data, SearchResult $result): void
    {
        try {
            SearchQuery::create([
                'query' => mb_substr($data->query, 0, 255),
                'filters' => $data->filters(),
                'total_hits' => $result->total,
                'took_ms' => $result->tookMs,
                'driver' => $result->driver,
            ]);
        } catch (\Throwable $e) {
            // Telemetry must never break a search response.
            Log::debug('Failed to record search telemetry', ['error' => $e->getMessage()]);
        }
    }
}
