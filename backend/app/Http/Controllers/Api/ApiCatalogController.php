<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ApiResource;
use App\Models\Api;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ApiCatalogController extends Controller
{
    /**
     * GET /api/apis - straight catalogue browse, no search engine involved.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $apis = Api::query()
            ->with(['category', 'provider'])
            ->when($request->filled('category'), fn ($q) => $q->whereHas('category', fn ($c) => $c->where('slug', $request->string('category'))))
            ->when($request->filled('auth'), fn ($q) => $q->where('authentication_type', $request->string('auth')))
            ->when($request->has('https'), fn ($q) => $q->where('https', $request->boolean('https')))
            ->orderByDesc('quality_score')
            ->orderBy('name')
            ->paginate(perPage: min((int) $request->input('per_page', 20), 100));

        return ApiResource::collection($apis);
    }

    /**
     * GET /api/apis/{slug}
     */
    public function show(Api $api): ApiResource
    {
        $api->load(['category', 'provider', 'endpoints', 'latestHealthCheck']);

        return new ApiResource($api);
    }
}
