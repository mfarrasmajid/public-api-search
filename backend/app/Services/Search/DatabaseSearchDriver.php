<?php

namespace App\Services\Search;

use App\Models\Api;
use Illuminate\Database\Eloquent\Builder;

/**
 * Fallback driver used when OpenSearch is down or not indexed yet.
 *
 * Plain ILIKE matching: no fuzziness, no real ranking. It exists so the POC
 * degrades instead of failing, and so `php artisan test` runs without a
 * search cluster. Never make this the primary path.
 */
class DatabaseSearchDriver implements SearchDriver
{
    public function name(): string
    {
        return 'database';
    }

    public function search(SearchQueryData $data): SearchResult
    {
        $start = microtime(true);

        $builder = Api::query()->with(['category', 'provider', 'latestHealthCheck']);

        $term = trim($data->query);
        if ($term !== '') {
            $like = '%'.str_replace('%', '\%', mb_strtolower($term)).'%';

            $builder->where(function (Builder $q) use ($like) {
                $driver = $q->getConnection()->getDriverName();
                $operator = $driver === 'pgsql' ? 'ilike' : 'like';

                $q->where('name', $operator, $like)
                    ->orWhere('description', $operator, $like)
                    ->orWhere('tags', $operator, $like)
                    ->orWhereHas('category', fn (Builder $c) => $c->where('name', $operator, $like))
                    ->orWhereHas('provider', fn (Builder $p) => $p->where('name', $operator, $like));
            });
        }

        $this->applyFilters($builder, $data);

        $total = (clone $builder)->count();

        match ($data->sort) {
            'quality' => $builder->orderByDesc('quality_score'),
            'name' => $builder->orderBy('name'),
            'updated' => $builder->orderByDesc('updated_at'),
            default => $builder->orderByDesc('quality_score')->orderBy('name'),
        };

        $apis = $builder->offset($data->offset())->limit($data->perPage)->get();

        $hits = $apis->map(function (Api $api) {
            $document = $api->toSearchDocument();
            $document['score'] = null; // no relevance score in fallback mode
            $document['highlight'] = null;

            return $document;
        })->all();

        return new SearchResult(
            hits: $hits,
            total: $total,
            maxScore: 0.0,
            tookMs: (int) round((microtime(true) - $start) * 1000),
            driver: $this->name(),
            facets: $this->facets($data),
        );
    }

    private function applyFilters(Builder $builder, SearchQueryData $data): void
    {
        $builder
            ->when($data->category, fn (Builder $q, $c) => $q->whereHas('category', fn (Builder $x) => $x->where('name', $c)))
            ->when($data->authentication, fn (Builder $q, $a) => $q->where('authentication_type', $a))
            ->when($data->https !== null, fn (Builder $q) => $q->where('https', $data->https))
            ->when($data->cors, fn (Builder $q, $c) => $q->where('cors', $c))
            ->when($data->country, fn (Builder $q, $c) => $q->where('country', $c))
            ->when($data->hasOpenapi !== null, fn (Builder $q) => $q->where('has_openapi', $data->hasOpenapi))
            ->where('status', '!=', 'dead');
    }

    private function facets(SearchQueryData $data): array
    {
        $categories = Api::query()
            ->join('categories', 'categories.id', '=', 'apis.category_id')
            ->selectRaw('categories.name as value, count(*) as count')
            ->groupBy('categories.name')
            ->orderByDesc('count')
            ->limit(30)
            ->get()
            ->map(fn ($row) => ['value' => $row->value, 'count' => (int) $row->count])
            ->all();

        $auth = Api::query()
            ->selectRaw('authentication_type as value, count(*) as count')
            ->groupBy('authentication_type')
            ->orderByDesc('count')
            ->get()
            ->map(fn ($row) => ['value' => $row->value, 'count' => (int) $row->count])
            ->all();

        return ['categories' => $categories, 'authentication' => $auth];
    }
}
