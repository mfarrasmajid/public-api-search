<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Api
 */
class ApiResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'category' => $this->category?->name,
            'provider' => $this->provider?->name,
            'tags' => $this->tags ?? [],
            'website' => $this->website,
            'documentation_url' => $this->documentation_url,
            'base_url' => $this->base_url,
            'authentication' => $this->authentication_type,
            'https' => (bool) $this->https,
            'cors' => $this->cors,
            'country' => $this->country,
            'status' => $this->status,
            'license' => $this->license,
            'has_openapi' => (bool) $this->has_openapi,
            'openapi_url' => $this->openapi_url,
            'quality_score' => (int) $this->quality_score,
            'health' => $this->whenLoaded('latestHealthCheck', fn () => $this->latestHealthCheck ? [
                'status' => $this->latestHealthCheck->status,
                'http_status' => $this->latestHealthCheck->http_status,
                'response_time_ms' => $this->latestHealthCheck->response_time_ms,
                'checked_at' => $this->latestHealthCheck->checked_at?->toIso8601String(),
            ] : null),
            'endpoints' => $this->whenLoaded('endpoints', fn () => $this->endpoints->map(fn ($endpoint) => [
                'method' => $endpoint->method,
                'path' => $endpoint->path,
                'description' => $endpoint->description,
                'parameters' => $endpoint->parameters,
            ])),
            'last_checked_at' => $this->last_checked_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
