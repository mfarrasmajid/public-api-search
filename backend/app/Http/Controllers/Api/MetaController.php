<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Api;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use OpenSearch\Client;

class MetaController extends Controller
{
    /**
     * GET /api/meta - everything the UI needs to draw its filter sidebar.
     */
    public function filters(): JsonResponse
    {
        return response()->json([
            'categories' => Category::query()
                ->withCount('apis')
                ->orderBy('name')
                ->get()
                ->map(fn (Category $c) => ['name' => $c->name, 'slug' => $c->slug, 'count' => $c->apis_count]),
            'authentication' => Api::query()
                ->selectRaw('authentication_type as value, count(*) as count')
                ->groupBy('authentication_type')
                ->orderByDesc('count')
                ->get(),
            'countries' => Api::query()
                ->whereNotNull('country')
                ->selectRaw('country as value, count(*) as count')
                ->groupBy('country')
                ->orderByDesc('count')
                ->get(),
            'total_apis' => Api::count(),
        ]);
    }

    /**
     * GET /api/health - readiness of the backend and its dependencies.
     */
    public function health(Client $opensearch): JsonResponse
    {
        $checks = [];

        try {
            DB::connection()->getPdo();
            $checks['database'] = ['ok' => true, 'apis' => Api::count()];
        } catch (\Throwable $e) {
            $checks['database'] = ['ok' => false, 'error' => $e->getMessage()];
        }

        try {
            $health = $opensearch->cluster()->health();
            $checks['opensearch'] = [
                'ok' => in_array($health['status'] ?? '', ['green', 'yellow'], true),
                'status' => $health['status'] ?? 'unknown',
                'indexed_documents' => $this->countDocuments($opensearch),
            ];
        } catch (\Throwable $e) {
            $checks['opensearch'] = ['ok' => false, 'error' => $e->getMessage()];
        }

        $ok = collect($checks)->every(fn ($check) => $check['ok'] === true);

        return response()->json([
            'ok' => $ok,
            'checks' => $checks,
        ], $ok ? 200 : 503);
    }

    private function countDocuments(Client $client): ?int
    {
        try {
            return (int) $client->count(['index' => config('opensearch.alias')])['count'];
        } catch (\Throwable) {
            return null;
        }
    }
}
